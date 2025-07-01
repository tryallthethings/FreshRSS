<?php

declare(strict_types=1);

/**
 * The controller to manage extensions.
 */
class FreshRSS_extension_Controller extends FreshRSS_ActionController {
	/**
	 * This action is called before every other action in that class. It is
	 * the common boiler plate for every action. It is triggered by the
	 * underlying framework.
	 */
	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	/**
	 * This action lists all the extensions available to the current user.
	 */
	public function indexAction(): void {
		FreshRSS_View::prependTitle(_t('admin.extensions.title') . ' · ');
		$this->view->extension_list = [
			'system' => [],
			'user' => [],
		];

		$this->view->extensions_installed = [];

		$extensions = Minz_ExtensionManager::listExtensions();
		foreach ($extensions as $ext) {
			$this->view->extension_list[$ext->getType()][] = $ext;
			$this->view->extensions_installed[$ext->getEntrypoint()] = $ext->getVersion();
		}

		$this->view->available_extensions = $this->getAvailableExtensionList();
	}

	/**
	 * Fetch extension list from GitHub
	 * @return list<array{name:string,author:string,description:string,version:string,entrypoint:string,type:'system'|'user',url:string,method:string,directory:string}>
	 */
	protected function getAvailableExtensionList(): array {
		$extensionListUrl = 'https://raw.githubusercontent.com/FreshRSS/Extensions/master/extensions.json';

		$cacheFile = CACHE_PATH . '/extension_list.json';
		if (FreshRSS_Context::userConf()->retrieve_extension_list === true) {
			if (!file_exists($cacheFile) || (time() - (filemtime($cacheFile) ?: 0) > 86400)) {
				$json = httpGet($extensionListUrl, $cacheFile, 'json')['body'];
			} else {
				$json = @file_get_contents($cacheFile) ?: '';
			}
		} else {
			Minz_Log::warning('The extension list retrieval is disabled in privacy configuration');
			return [];
		}

		// we ran into problems, simply ignore them
		if ($json === '') {
			Minz_Log::error('Could not fetch available extension from GitHub');
			return [];
		}

		// fetch the list as an array
		/** @var array<string,mixed> $list*/
		$list = json_decode($json, true);
		if (!is_array($list) || empty($list['extensions']) || !is_array($list['extensions'])) {
			Minz_Log::warning('Failed to convert extension file list');
			return [];
		}

		// By now, all the needed data is kept in the main extension file.
		// In the future we could fetch detail information from the extensions metadata.json, but I tend to stick with
		// the current implementation for now, unless it becomes too much effort maintain the extension list manually
		$extensions = [];
		foreach ($list['extensions'] as $extension) {
			if (!is_array($extension)) {
				continue;
			}
			if (isset($extension['version']) && is_numeric($extension['version'])) {
				$extension['version'] = (string)$extension['version'];
			}
			$keys = ['author', 'description', 'directory', 'entrypoint', 'method', 'name', 'type', 'url', 'version'];
			$extension = array_intersect_key($extension, array_flip($keys));	// Keep only valid keys
			$extension = array_filter($extension, 'is_string');
			foreach ($keys as $key) {
				if (empty($extension[$key])) {
					continue 2;
				}
			}
			if (!in_array($extension['type'], ['system', 'user'], true) || trim($extension['name']) === '') {
				continue;
			}
			/** @var array{name:string,author:string,description:string,version:string,entrypoint:string,type:'system'|'user',url:string,method:string,directory:string} $extension */
			$extensions[] = $extension;
		}
		return $extensions;
	}

	/**
	 * This action handles configuration of a given extension.
	 *
	 * Only administrator can configure a system extension.
	 *
	 * Parameters are:
	 * - e: the extension name (urlencoded)
	 * - additional parameters which should be handle by the extension
	 *   handleConfigureAction() method (POST request).
	 */
	public function configureAction(): void {
		if (Minz_Request::paramBoolean('ajax')) {
			$this->view->_layout(null);
		} elseif (Minz_Request::paramBoolean('slider')) {
			$this->indexAction();
			$this->view->_path('extension/index.phtml');
		}

		$ext_name = urldecode(Minz_Request::paramString('e'));
		$ext = Minz_ExtensionManager::findExtension($ext_name);

		if ($ext === null) {
			Minz_Error::error(404);
			return;
		}
		if ($ext->getType() === 'system' && !FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
			return;
		}

		FreshRSS_View::prependTitle($ext->getName() . ' · ' . _t('admin.extensions.title') . ' · ');
		$this->view->extension = $ext;
		try {
			$this->view->extension->handleConfigureAction();
		} catch (Minz_Exception $e) {	// @phpstan-ignore catch.neverThrown (Thrown by extensions)
			Minz_Log::error('Error while configuring extension ' . $ext->getName() . ': ' . $e->getMessage());
			Minz_Request::bad(_t('feedback.extensions.enable.ko', $ext_name, _url('index', 'logs')), ['c' => 'extension', 'a' => 'index']);
		}
	}

	/**
	 * This action enables a disabled extension for the current user.
	 *
	 * System extensions can only be enabled by an administrator.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * - e: the extension name (urlencoded).
	 */
	public function enableAction(): void {
		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			if ($ext->isEnabled()) {
				Minz_Request::bad(_t('feedback.extensions.already_enabled', $ext_name), $url_redirect);
			}

			$type = $ext->getType();
			if ($type !== 'user' && !FreshRSS_Auth::hasAccess('admin')) {
				Minz_Request::bad(_t('feedback.extensions.no_access', $ext_name), $url_redirect);
				return;
			}

			$conf = null;
			if ($type === 'system') {
				$conf = FreshRSS_Context::systemConf();
			} elseif ($type === 'user') {
				$conf = FreshRSS_Context::userConf();
			}

			$res = $ext->install();

			if ($conf !== null && $res === true) {
				$ext_list = $conf->extensions_enabled;
				$ext_list = array_filter($ext_list, static function (string $key) use ($type) {
					// Remove from list the extensions that have disappeared or changed type
					$extension = Minz_ExtensionManager::findExtension($key);
					return $extension !== null && $extension->getType() === $type;
				}, ARRAY_FILTER_USE_KEY);

				$ext_list[$ext_name] = true;
				$conf->extensions_enabled = $ext_list;
				$conf->save();

				Minz_Request::good(_t('feedback.extensions.enable.ok', $ext_name), $url_redirect);
			} else {
				Minz_Log::warning('Cannot enable extension ' . $ext_name . ': ' . $res);
				Minz_Request::bad(_t('feedback.extensions.enable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	/**
	 * This action disables an enabled extension for the current user.
	 *
	 * System extensions can only be disabled by an administrator.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * - e: the extension name (urlencoded).
	 */
	public function disableAction(): void {
		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			if (!$ext->isEnabled()) {
				Minz_Request::bad(_t('feedback.extensions.not_enabled', $ext_name), $url_redirect);
			}

			$type = $ext->getType();
			if ($type !== 'user' && !FreshRSS_Auth::hasAccess('admin')) {
				Minz_Request::bad(_t('feedback.extensions.no_access', $ext_name), $url_redirect);
				return;
			}

			$conf = null;
			if ($type === 'system') {
				$conf = FreshRSS_Context::systemConf();
			} elseif ($type === 'user') {
				$conf = FreshRSS_Context::userConf();
			}

			$res = $ext->uninstall();

			if ($conf !== null && $res === true) {
				$ext_list = $conf->extensions_enabled;
				$ext_list = array_filter($ext_list, static function (string $key) use ($type) {
					// Remove from list the extensions that have disappeared or changed type
					$extension = Minz_ExtensionManager::findExtension($key);
					return $extension !== null && $extension->getType() === $type;
				}, ARRAY_FILTER_USE_KEY);

				$ext_list[$ext_name] = false;
				$conf->extensions_enabled = $ext_list;
				$conf->save();

				Minz_Request::good(_t('feedback.extensions.disable.ok', $ext_name), $url_redirect);
			} else {
				Minz_Log::warning('Cannot disable extension ' . $ext_name . ': ' . $res);
				Minz_Request::bad(_t('feedback.extensions.disable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	/**
	 * This action handles deletion of an extension.
	 *
	 * Only administrator can remove an extension.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * -e: extension name (urlencoded)
	 */
	public function removeAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
		}

		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			$res = recursive_unlink($ext->getPath());
			if ($res) {
				Minz_Request::good(_t('feedback.extensions.removed', $ext_name), $url_redirect);
			} else {
				Minz_Request::bad(_t('feedback.extensions.cannot_remove', $ext_name), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	// Supported types with their associated content type
	public const MIME_TYPES = [
		'css' => 'text/css; charset=UTF-8',
		'gif' => 'image/gif',
		'jpeg' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'js' => 'application/javascript; charset=UTF-8',
		'png' => 'image/png',
		'svg' => 'image/svg+xml',
	];

	public function serveAction(): void {
		$extensionName = Minz_Request::paramString('x');
		$filename = Minz_Request::paramString('f');
		$mimeType = pathinfo($filename, PATHINFO_EXTENSION);
		if ($extensionName === '' || $filename === '' || $mimeType === '' || empty(self::MIME_TYPES[$mimeType])) {
			header('HTTP/1.1 400 Bad Request');
			die('Bad Request!');
		}
		$extension = Minz_ExtensionManager::findExtension($extensionName);
		if ($extension === null || !$extension->isEnabled() || ($mtime = $extension->mtimeFile($filename)) === null) {
			header('HTTP/1.1 404 Not Found');
			die('Not Found!');
		}

		$this->view->_layout(null);

		$content_type = self::MIME_TYPES[$mimeType];
		header("Content-Type: {$content_type}");
		header("Content-Disposition: inline; filename='{$filename}'");
		header('Referrer-Policy: same-origin');
		if (!httpConditional($mtime, cacheSeconds: 604800, cachePrivacy: 2)) {
			echo $extension->getFile($filename);
		}
	}

	public function downloadAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
		}

		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (!Minz_Request::isPost()) {
			Minz_Request::forward($url_redirect, true);
			return;
		}

		$entry = urldecode(Minz_Request::paramString('e'));
		$info = null;
		foreach ($this->getAvailableExtensionList() as $ext) {
			if ($ext['entrypoint'] === $entry) {
				$info = $ext;
				break;
			}
		}

		if ($info === null) {
			$info = [
				'name' => $entry,
				'entrypoint' => $entry,
				'version' => Minz_Request::paramString('version'),
				'url' => Minz_Request::paramString('url'),
				'method' => '',
				'directory' => Minz_Request::paramString('directory'),
			];
		}

		$existingExt = Minz_ExtensionManager::findExtension($info['entrypoint']);
		$isUpdate = $existingExt !== null;

		Minz_Log::notice(($isUpdate ? 'Updating' : 'Installing') . ' extension: ' . $info['name'] . ' v' . $info['version']);

		$ok = $this->downloadExtension($info);
		if ($ok) {
			$message = $isUpdate
				? _t('feedback.extensions.updated', $info['name'])
				: _t('feedback.extensions.installed', $info['name']);
			Minz_Log::notice('Extension ' . $info['entrypoint'] . ' ' . ($isUpdate ? 'updated' : 'installed') . ' successfully');
			Minz_Request::good($message, $url_redirect);
		} else {
			$message = $isUpdate
				? _t('feedback.extensions.update_failed', $info['name'])
				: _t('feedback.extensions.install_failed', $info['name']);
			Minz_Log::error('Extension ' . $info['entrypoint'] . ' ' . ($isUpdate ? 'update' : 'installation') . ' failed');
			Minz_Request::bad($message, $url_redirect);
		}
	}


	/**
	 * Downloads and installs an extension from a remote repository
	 *
	 * @param array{name:string,entrypoint:string,version:string,url:string,method:string,directory:string} $info Extension information
	 * @return bool True if the extension was successfully downloaded and installed, false otherwise
	 */
	private function downloadExtension(array $info): bool {
		Minz_Log::notice('Downloading extension ' . $info['entrypoint']);
		$archive = $this->getExtensionArchiveUrl($info);
		if ($archive === '') {
			Minz_Log::error('Cannot determine archive URL for ' . $info['entrypoint']);
			return false;
		}

		$tmpFile = tempnam(TMP_PATH, 'ext');
		if ($tmpFile === false) {
			Minz_Log::error('Could not create temporary file for download.');
			return false;
		}

		if (!$this->downloadFile($archive, $tmpFile)) {
			unlink($tmpFile);
			return false;
		}

		$tmpDir = tempnam(TMP_PATH, 'ext');
		if ($tmpDir === false) {
			Minz_Log::error('Could not create temporary directory for extraction.');
			unlink($tmpFile);
			return false;
		}

		unlink($tmpDir);
		if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
			unlink($tmpFile);
			return false;
		}
		$zip = new ZipArchive();
		if ($zip->open($tmpFile) !== true) {
			unlink($tmpFile);
			recursive_unlink($tmpDir);
			Minz_Log::error('Invalid zip archive for ' . $info['entrypoint']);
			return false;
		}
		$zip->extractTo($tmpDir);
		$zip->close();
		unlink($tmpFile);

		// Repository archives often contain a single root folder (e.g., my-repo-main).
		// We need to find the actual extension source inside the extracted files.
		$entries = array_values(array_diff(scandir($tmpDir) ?: [], ['.', '..']));
		$root = $tmpDir;
		if (count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0])) {
			$root = $tmpDir . '/' . $entries[0];
		}

		$source = $root . '/' . $info['directory'];
		if (!is_dir($source)) {
			$source = $root;
		}

		$metaFile = $source . '/metadata.json';
		if (!is_file($metaFile)) {
			recursive_unlink($tmpDir);
			Minz_Log::error('Missing metadata.json for ' . $info['entrypoint']);
			return false;
		}

		$meta = json_decode(file_get_contents($metaFile) ?: '', true);
		if (!is_array($meta) || empty($meta['entrypoint']) || !is_string($meta['entrypoint'])) {
			recursive_unlink($tmpDir);
			Minz_Log::error('Invalid metadata.json for ' . $info['entrypoint']);
			return false;
		}

		$dest = THIRDPARTY_EXTENSIONS_PATH . '/' . basename($meta['entrypoint']);
		// Security: Ensure destination is within the extensions directory
		$realDest = realpath(dirname($dest));
		$realExtPath = realpath(THIRDPARTY_EXTENSIONS_PATH);
		if ($realDest === false || $realExtPath === false || strpos($realDest, $realExtPath) !== 0) {
			recursive_unlink($tmpDir);
			Minz_Log::error('Invalid destination path for ' . $info['entrypoint']);
			return false;
		}

		// Check if removal was successful
		if (!$this->removeExistingExtension($meta['entrypoint'])) {
			recursive_unlink($tmpDir);
			Minz_Log::error('Failed to remove existing extension ' . $info['entrypoint'] . '. Cannot proceed with update.');
			return false;
		}

		$res = $this->recursiveCopy($source, $dest);
		recursive_unlink($tmpDir);

		if ($res) {
			$this->sanitizeExtension($dest);
			Minz_Log::notice('Extension ' . $info['entrypoint'] . ' installed to ' . $dest);
		}

		return $res;
	}

	/**
	 * Determines the archive download URL for an extension based on the repository platform
	 *
	 * Supports GitHub, GitLab, and Forgejo/Gitea repositories. Falls back to default branch
	 * if no release is found for the specified version.
	 *
	 * @param array{name:string,entrypoint:string,version:string,url:string,method:string,directory:string} $info Extension information
	 * @return string Archive download URL, or empty string if unable to determine
	 */
	private function getExtensionArchiveUrl(array $info): string {
		$url = rtrim($info['url'], '/');
		$version = $info['version'];
		$repo = $this->detectRepositoryPlatform($url);

		if ($repo === null) {
			Minz_Log::warning('Unknown repository platform for ' . $url . ', cannot determine archive URL.');
			return '';
		}

		// Handle GitHub repositories
		if ($repo['platform'] === 'github') {
			$api = $repo['api_base'] . '/repos/' . $repo['project_path'] . '/releases/tags/' . rawurlencode($version);
			$json = $this->downloadJson($api);
			if (is_array($json) && !empty($json['zipball_url']) && is_string($json['zipball_url'])) {
				return $json['zipball_url'];
			}

			Minz_Log::debug('No GitHub release found for version ' . $version . ', falling back to default branch.');
			$repoInfo = $this->downloadJson($repo['api_base'] . '/repos/' . $repo['project_path']);
			if (is_array($repoInfo) && !empty($repoInfo['default_branch']) && is_string($repoInfo['default_branch'])) {
				return $url . '/archive/refs/heads/' . rawurlencode($repoInfo['default_branch']) . '.zip';
			}
			Minz_Log::error('Failed to detect default branch for GitHub repository: ' . $url);
			return '';
		}

		// Handle Forgejo / Gitea repositories
		if ($repo['platform'] === 'forgejo') {
			// Forgejo uses similar API to Gitea
			$api = $repo['api_base'] . '/repos/' . $repo['project_path'] . '/releases/tags/' . rawurlencode($version);
			$json = $this->downloadJson($api);
			if (is_array($json) && !empty($json['zipball_url']) && is_string($json['zipball_url'])) {
				return $json['zipball_url'];
			}

			Minz_Log::debug('No Forgejo/Gitea release found for version ' . $version . ', falling back to default branch.');
			$repoInfo = $this->downloadJson($repo['api_base'] . '/repos/' . $repo['project_path']);
			if (is_array($repoInfo) && !empty($repoInfo['default_branch']) && is_string($repoInfo['default_branch'])) {
				return $url . '/archive/' . rawurlencode($repoInfo['default_branch']) . '.zip';
			}

			Minz_Log::error('Failed to detect default branch for Forgejo/Gitea repository: ' . $url);
			return '';
		}

		// Handle GitLab repositories
		if ($repo['platform'] === 'gitlab') {
			$encoded = rawurlencode($repo['project_path']);
			$baseApiUrl = $repo['api_base'] . '/projects/' . $encoded;
			$release = $this->downloadJson($baseApiUrl . '/releases/' . rawurlencode($version));
			if (
				is_array($release) && !empty($release['assets']) && is_array($release['assets'])
				&& !empty($release['assets']['sources']) && is_array($release['assets']['sources'])
				&& !empty($release['assets']['sources'][0]) && is_array($release['assets']['sources'][0])
				&& !empty($release['assets']['sources'][0]['url']) && is_string($release['assets']['sources'][0]['url'])
			) {
				return $release['assets']['sources'][0]['url'];
			}

			Minz_Log::debug('No GitLab release found for version ' . $version . ', falling back to default branch.');
			$projectInfo = $this->downloadJson($baseApiUrl);
			if (is_array($projectInfo) && !empty($projectInfo['default_branch']) && is_string($projectInfo['default_branch'])) {
				$branch = $projectInfo['default_branch'];
				return $url . '/-/archive/' . rawurlencode($branch) . '/' . $repo['repo'] . '-' . rawurlencode($branch) . '.zip';
			}
			Minz_Log::error('Failed to detect default branch for GitLab repository: ' . $url);
			return '';
		}

		Minz_Log::warning('Unknown repository platform for ' . $url . ', cannot determine archive URL');
		return '';
	}

	/**
	 * Detects the repository platform (GitHub, GitLab, Forgejo/Gitea) from a URL.
	 *
	 * It uses a multi-step process: first, a hostname check for github.com.
	 * Second, it probes known API endpoints for self-hosted instances.
	 * Finally, it falls back to inspecting HTML content for platform markers.
	 *
	 * @param string $url The repository URL.
	 * @return array{platform:string,api_base:string,project_path:string,owner:string,repo:string}|null Platform information or null if detection fails.
	 */
	private function detectRepositoryPlatform(string $url): ?array {
		$parsed = parse_url($url);
		if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host']) || empty($parsed['path'])) {
			return null;
		}

		$host = $parsed['host'];
		$base = $parsed['scheme'] . '://' . $host . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
		$path = trim($parsed['path'], '/');
		$parts = explode('/', $path);
		if (count($parts) < 2) {
			return null;
		}

		$repoName = preg_replace('/\.git$/', '', (string)array_pop($parts));
		if ($repoName === null || $repoName === '') {
			return null;
		}
		$owner = implode('/', $parts);
		$project = $owner . '/' . $repoName;

		// Hostname check for GitHub, which is unambiguous.
		if ($host === 'github.com') {
			return [
				'platform' => 'github',
				'api_base' => 'https://api.github.com',
				'project_path' => $project,
				'owner' => $owner,
				'repo' => $repoName,
			];
		}

		// API endpoint probing for self-hosted instances.
		$context = stream_context_create([
			'http' => [
				'header' => ['User-Agent: ' . FRESHRSS_USERAGENT],
				'timeout' => 10,
				'ignore_errors' => true,
			],
		]);

		// Try Forgejo/Gitea API (v1).
		$response = file_get_contents($base . '/api/v1/version', false, $context);
		if ($response !== false) {
			$json = json_decode($response, true);
			// Check for a valid JSON response with a version string.
			if (is_array($json) && !empty($json['version']) && is_string($json['version'])) {
				Minz_Log::debug('Detected Forgejo/Gitea instance at ' . $host);
				return [
					'platform' => 'forgejo',
					'api_base' => $base . '/api/v1',
					'project_path' => $project,
					'owner' => $owner,
					'repo' => $repoName,
				];
			}
		}

		// Try GitLab API (v4).
		$response = file_get_contents($base . '/api/v4/projects/' . rawurlencode($project), false, $context);
		if ($response !== false) {
			$json = json_decode($response, true);
			// Check for a valid JSON response with a GitLab-specific key.
			if (is_array($json) && isset($json['path_with_namespace'])) {
				Minz_Log::debug('Detected GitLab instance at ' . $host);
				return [
					'platform' => 'gitlab',
					'api_base' => $base . '/api/v4',
					'project_path' => $project,
					'owner' => $owner,
					'repo' => $repoName,
				];
			}
		}

		// 3. HTML content inspection as a fallback.
		$page = file_get_contents($url, false, $context);
		if ($page !== false) {
			// Check for Forgejo/Gitea markers
			if (preg_match('/forgejo|gitea|data-gitea-/i', $page)) {
				Minz_Log::debug('Detected Forgejo/Gitea instance at ' . $host . ' via HTML inspection');
				return [
					'platform' => 'forgejo',
					'api_base' => $base . '/api/v1',
					'project_path' => $project,
					'owner' => $owner,
					'repo' => $repoName,
				];
			}

			// Check for GitLab markers
			if (preg_match('/gitlab|gl-|data-project-id/i', $page)) {
				Minz_Log::debug('Detected GitLab instance at ' . $host . ' via HTML inspection');
				return [
					'platform' => 'gitlab',
					'api_base' => $base . '/api/v4',
					'project_path' => $project,
					'owner' => $owner,
					'repo' => $repoName,
				];
			}
		}

		Minz_Log::warning('Could not detect repository platform for: ' . $url);
		return null;
	}

	/**
	 * Downloads a file from a URL using cURL with proper security settings.
	 *
	 * @param string $url Source URL (must be http or https).
	 * @param string $dest Destination file path.
	 * @return bool True if download succeeded, false otherwise.
	 */
	private function downloadFile(string $url, string $dest): bool {
		// Validate URL scheme
		$parsed = parse_url($url);
		if (!is_array($parsed) || !isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
			Minz_Log::error('Invalid URL scheme for download: ' . $url);
			return false;
		}

		$ch = curl_init($url);
		if ($ch === false) {
			return false;
		}
		$fp = fopen($dest, 'wb');
		if ($fp === false) {
			curl_close($ch);
			return false;
		}

		$headers = [];
		// GitHub API requires specific headers for API access.
		if (str_contains($url, 'api.github.com')) {
			$headers[] = 'Accept: application/vnd.github+json';
			$headers[] = 'X-GitHub-Api-Version: 2022-11-28';
		}

		curl_setopt_array($ch, [
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		fclose($fp);

		if ($result === false || $code < 200 || $code >= 300) {
			unlink($dest);
			Minz_Log::error('Download failed from ' . $url . ' (HTTP ' . $code . '): ' . $error);
			return false;
		}

		return true;
	}

	/**
	 * Downloads and parses JSON data from a URL.
	 *
	 * @param string $url The JSON endpoint URL.
	 * @return array<mixed,mixed>|null Parsed JSON data as an associative array, or null on failure.
	 */
	private function downloadJson(string $url): ?array {
		$ch = curl_init($url);
		if ($ch === false) {
			Minz_Log::warning('Failed to initialize cURL for: ' . $url);
			return null;
		}

		$responseHeaders = [];
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ($curl, $header) use (&$responseHeaders) {
				if (!is_string($header)) {
					return 0;
				}
				$len = strlen($header);
				$headerParts = explode(':', $header, 2);
				if (count($headerParts) < 2) {
					return $len;
				}
				$responseHeaders[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
				return $len;
			}
		);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'Cache-Control: no-cache',
			],
		]);

		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === false || !is_string($body)) {
			Minz_Log::warning('Network error fetching JSON from ' . $url . ': ' . $error);
			return null;
		}

		if ($code === 403 && str_contains($url, 'api.github.com')) {
			$resetDate = 'an hour';
			if (isset($responseHeaders['x-ratelimit-reset'])) {
				$resetDate = 'at ' . gmdate('H:i:s T', (int)$responseHeaders['x-ratelimit-reset']);
			}
			Minz_Log::error('GitHub API rate limit exceeded. Reset: ' . $resetDate);
			$message = _t('feedback.extensions.install_failed_rate_limited_generic', $resetDate);
			Minz_Request::bad($message, ['c' => 'extension', 'a' => 'index']);
			// The Minz_Request::bad call will handle the redirect and exit.
		}

		if ($code === 404) {
			Minz_Log::debug('Resource not found (404): ' . $url);
			return null;
		}

		if ($code < 200 || $code >= 300) {
			Minz_Log::warning('HTTP error ' . $code . ' fetching JSON from: ' . $url);
			return null;
		}

		$json = json_decode($body, true);
		if (!is_array($json)) {
			Minz_Log::warning('Invalid JSON response from: ' . $url);
			return null;
		}

		return $json;
	}

	/**
	 * Removes potentially dangerous or unnecessary files from an extension directory.
	 *
	 * This function recursively scans for files and directories matching a list of
	 * patterns (e.g., VCS directories, temp files, config files) and deletes them.
	 *
	 * @param string $path Extension directory path.
	 */
	private function sanitizeExtension(string $path): void {
		$patterns = [
			// VCS and CI
			'.git',
			'.gitignore',
			'.gitattributes',
			'.gitmodules',
			'.gitlab-ci.yml',
			'.travis.yml',
			'appveyor.yml',
			'.github',
			'.circleci',

			// Development artifacts
			'tests',
			'test',
			'spec',
			'node_modules',
			'.idea',
			'.vscode',
			'*.sublime*',
			'composer.phar',
			'yarn.lock',
			'package-lock.json',
			'*.log',

			// Config and sensitive files
			'.env*',
			'*.key',
			'*.pem',
			'*.p12',
			'*.pfx',
			'*.crt',
			'id_rsa*',
			'id_dsa*',
			'*.secret',
			'*.credentials',

			// OS and temporary files
			'*.swp',
			'*.swo',
			'*~',
			'*.bak',
			'*.tmp',
			'.DS_Store',
			'Thumbs.db',
		];

		if (!is_dir($path)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/** @var SplFileInfo $file */
		foreach ($iterator as $file) {
			$filename = $file->getFilename();
			foreach ($patterns as $pattern) {
				if (fnmatch($pattern, $filename)) {
					Minz_Log::debug('Sanitizing: removing `' . $file->getPathname() . '` matching pattern `' . $pattern . '`');
					recursive_unlink($file->getPathname());
					// Once unlinked, continue to the next file in the iterator.
					continue 2;
				}
			}
		}
	}

	/**
	 * Removes an existing extension directory by its entrypoint name.
	 *
	 * It first attempts to find the extension via the ExtensionManager. As a fallback,
	 * it scans the extensions directory and inspects `metadata.json` files to find a match.
	 *
	 * @param string $entrypoint The entrypoint identifier of the extension.
	 * @return bool True on success, false on failure to remove.
	 */
	private function removeExistingExtension(string $entrypoint): bool {
		// First try via extension manager
		$ext = Minz_ExtensionManager::findExtension($entrypoint);
		if ($ext !== null) {
			return recursive_unlink($ext->getPath());
		}

		// Fallback for cases where the extension is not loaded by the manager.
		$directories = scandir(THIRDPARTY_EXTENSIONS_PATH) ?: [];
		foreach ($directories as $dir) {
			if ($dir === '.' || $dir === '..') {
				continue;
			}
			$metaFile = THIRDPARTY_EXTENSIONS_PATH . '/' . $dir . '/metadata.json';
			if (is_file($metaFile)) {
				$data = json_decode(file_get_contents($metaFile) ?: '', true);
				if (is_array($data) && ($data['entrypoint'] ?? '') === $entrypoint) {
					return recursive_unlink(THIRDPARTY_EXTENSIONS_PATH . '/' . $dir);
				}
			}
		}

		return true;
	}

	/**
	 * Recursively copies files and directories from a source to a destination.
	 *
	 * @param string $src Source path.
	 * @param string $dest Destination path.
	 * @return bool True if copy succeeded, false otherwise.
	 */
	private function recursiveCopy(string $src, string $dest): bool {
		// Security: Do not follow symbolic links to prevent directory traversal.
		if (is_link($src)) {
			return true; // Skip symlinks
		}

		if (is_dir($src)) {
			if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
				return false;
			}

			$files = array_diff((array) scandir($src), ['.', '..']);
			/** @var string $file */
			foreach ($files as $file) {
				if (!$this->recursiveCopy($src . '/' . $file, $dest . '/' . $file)) {
					return false;
				}
			}
		} elseif (!copy($src, $dest)) {
			return false;
		}

		return true;
	}

	/**
	 * Gets the release URL for an extension based on its repository platform.
	 *
	 * @param array{url:string,version:string} $ext Extension data including URL and version.
	 * @return string|null The release URL if the platform is recognized, otherwise null.
	 */
	public static function getReleaseUrl(array $ext): ?string {
		$url = rtrim($ext['url'], '/');
		$version = $ext['version'];

		// Common platform URL patterns for releases.
		if (str_contains($url, 'github.com')) {
			return $url . '/releases/tag/' . rawurlencode($version);
		}
		if (str_contains($url, 'gitlab') || str_contains($url, 'framagit.org')) {
			return $url . '/-/releases/' . rawurlencode($version);
		}
		if (str_contains($url, 'gitea') || str_contains($url, 'forgejo') || str_contains($url, 'codeberg.org')) {
			return $url . '/releases/tag/' . rawurlencode($version);
		}

		return null;
	}

	/**
	 * Updates all installed extensions that have a newer version available.
	 *
	 * This action is restricted to administrators and must be called via a POST request.
	 */
	public function updateallAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
			return;
		}

		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (!Minz_Request::isPost()) {
			Minz_Request::forward($url_redirect, true);
			return;
		}

		Minz_Log::notice('Starting batch extension update process.');

		// Build the installed extensions list like in indexAction
		$installedExtensions = [];
		foreach (Minz_ExtensionManager::listExtensions() as $ext) {
			$installedExtensions[$ext->getEntrypoint()] = $ext->getVersion();
		}

		$available = $this->getAvailableExtensionList();
		$updatedCount = 0;
		$failedCount = 0;
		$failedNames = [];

		foreach ($available as $ext) {
			$entrypoint = $ext['entrypoint'];
			if (
				isset($installedExtensions[$entrypoint]) &&
				version_compare($installedExtensions[$entrypoint], (string) $ext['version']) < 0
			) {
				Minz_Log::notice('Updating extension: ' . $ext['name'] . ' from v' . $installedExtensions[$entrypoint] . ' to v' . $ext['version']);

				if ($this->downloadExtension($ext)) {
					$updatedCount++;
				} else {
					$failedCount++;
					$failedNames[] = $ext['name'];
				}
			}
		}

		if ($failedCount === 0 && $updatedCount > 0) {
			Minz_Request::good(_t('feedback.extensions.update_all.ok', $updatedCount), $url_redirect);
		} elseif ($updatedCount === 0 && $failedCount === 0) {
			Minz_Request::good(_t('feedback.extensions.update_all.none'), $url_redirect);
		} else {
			$message = _t('feedback.extensions.update_all.partial', $updatedCount, $failedCount);
			if (!empty($failedNames)) {
				$message .= ' ' . _t('feedback.extensions.update_all.failed_list', implode(', ', $failedNames));
			}
			Minz_Request::bad($message, $url_redirect);
		}
	}
}

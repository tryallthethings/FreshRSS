<?php // FreshRSS-Netvibes/extension.php

class DashboardViewExtension extends Minz_Extension {

	// --- Constants ---
	public const CONTROLLER_NAME_BASE = 'dbview';
	public const EXT_ID = 'DashboardView';
	// Config Keys
	public const LAYOUT_CONFIG_KEY_V1 = self::EXT_ID . '_layout'; // Legacy
	public const LAYOUT_CONFIG_KEY = self::EXT_ID . '_layout_v2';   // New key for tabbed layout
	public const ACTIVE_TAB_CONFIG_KEY = self::EXT_ID . '_active_tab';
	public const LIMIT_CONFIG_PREFIX = 'limit_'; // Per-feed limit key prefix
	public const FONT_SIZE_CONFIG_PREFIX = 'fontsize_'; // Per-feed font size key prefix
	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 5;
	public const ALLOWED_LIMIT_VALUES = [5, 15, 25];
	// Font Sizes
	public const ALLOWED_FONT_SIZES = ['small', 'regular', 'large'];
	public const DEFAULT_FONT_SIZE = 'regular';
	// --- End Constants ---

	public function init(): void {
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);

		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('Sortable.min.js', 'js'), '', false);
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'), '', false);
	}

	/** Hook callback to register the dashboard as a reading mode. */
	public static function addReadingMode(array $readingModes): array {
		$urlParams = array_merge(Minz_Request::currentRequest(), [
			'c' => self::CONTROLLER_NAME_BASE,
			'a' => 'index',
		]);
		$isActive = Minz_Request::controllerName() === self::CONTROLLER_NAME_BASE
			&& Minz_Request::actionName() === 'index';

		$mode = new FreshRSS_ReadingMode(
			'view-dashboard',
			_t(self::EXT_ID . '.title', 'Dashboard'),
			$urlParams,
			$isActive
		);
		$mode->setName('📊');
		$readingModes[] = $mode;
		return $readingModes;
	}

  /**
	 * Handles the logic when the configuration form is submitted.
	 */
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$userConf = FreshRSS_Context::userConf();

			if (!isset($userConf->extensions[$this->getId()])) {
				$userConf->extensions[$this->getId()] = new stdClass();
			}
			$extConf = $userConf->extensions[$this->getId()];

			$extConf->refresh_enabled = Minz_Request::param('refresh_enabled') === 'on';
			$extConf->refresh_interval = Minz_Request::paramInt('refresh_interval', 15);
			$extConf->date_format = Minz_Request::paramString('date_format', 'Y-m-d H:i');
			
			$userConf->save();
			Minz_Session::add('feedback', ['success', _t('feedback.success.save')]);
			Minz_Url::redirect(Minz_Url::display(['c' => 'configure', 'a' => 'extension', 'e' => $this->getId()]));
		}
	}

	/**
	 * A helper to get a specific setting's value for this extension.
	 * @param string $key The setting key.
	 * @param mixed $default The default value to return if not set.
	 * @return mixed The setting value.
	 */
	public function getSetting(string $key, $default = null) {
		$userConf = FreshRSS_Context::userConf();
		// We use the null coalescing operator to safely access the values
		return $userConf->extensions[$this->getId()]->{$key} ?? $default;
	}
}
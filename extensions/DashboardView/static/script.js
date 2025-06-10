document.addEventListener('freshrss:globalContextLoaded', () => {
	const dashboardView = document.querySelector('.dashboard-view');
	if (dashboardView) {
		initializeDashboard(dashboardView);
	}
});

function initializeDashboard(dashboardView) {
	// --- STATE ---
	let state = { layout: [], feeds: {}, activeTabId: null };

	// --- DOM & CONFIG ---
	const { getLayoutUrl, saveLayoutUrl, tabActionUrl, setActiveTabUrl, csrfToken, saveFeedSettingsUrl } = dashboardView.dataset;
	const tabsContainer = dashboardView.querySelector('.dashboard-tabs');
	const panelsContainer = dashboardView.querySelector('.dashboard-panels');
	const templates = {
		tabLink: document.getElementById('template-tab-link'),
		tabPanel: document.getElementById('template-tab-panel'),
		feedContainer: document.getElementById('template-feed-container'),
	};

	// --- RENDER FUNCTIONS ---
	function render() {
		renderTabs();
		renderPanels();
		activateTab(state.activeTabId || state.layout[0]?.id, false);
	}

	function renderTabs() {
		tabsContainer.innerHTML = '';
		state.layout.forEach(tab => tabsContainer.appendChild(createTabLink(tab)));
		const addButton = document.createElement('button');
		addButton.type = 'button';
		addButton.className = 'tab-add-button';
		addButton.textContent = '+';
		addButton.ariaLabel = 'Add new tab';
		tabsContainer.appendChild(addButton);
	}

	function renderPanels() {
		panelsContainer.innerHTML = '';
		state.layout.forEach(tab => panelsContainer.appendChild(createTabPanel(tab)));
	}

	function createTabLink(tab) {
		const link = templates.tabLink.content.cloneNode(true).firstElementChild;
		link.dataset.tabId = tab.id;
		link.querySelector('.tab-name').textContent = tab.name;
		return link;
	}

	function createTabPanel(tab) {
		const panel = templates.tabPanel.content.cloneNode(true).firstElementChild;
		panel.id = tab.id;
		return panel;
	}

	function renderTabContent(tab) {
		const panel = document.getElementById(tab.id);
		if (!panel) return;

		const columnsContainer = panel.querySelector('.dashboard-columns');
		columnsContainer.innerHTML = '';
		columnsContainer.className = `dashboard-columns columns-${tab.num_columns}`;
		
		const columns = Array.from({ length: tab.num_columns }, (_, i) => {
			const colDiv = document.createElement('div');
			colDiv.className = 'dashboard-column';
			colDiv.dataset.columnId = `col${i + 1}`;
			columnsContainer.appendChild(colDiv);
			return colDiv;
		});

		const placedFeedIds = new Set(Object.values(tab.columns).flat().map(String));
		
		Object.entries(tab.columns).forEach(([colId, feedIds]) => {
			const colIndex = parseInt(colId.replace('col', ''), 10) - 1;
			if (columns[colIndex]) {
				feedIds.forEach(feedId => {
					const feedData = state.feeds[feedId];
					if (feedData) {
						columns[colIndex].appendChild(createFeedContainer(feedData));
					}
				});
			}
		});

		Object.values(state.feeds).forEach(feedData => {
			if (!placedFeedIds.has(String(feedData.id))) {
				columns[0].appendChild(createFeedContainer(feedData));
			}
		});

		initializeSortable(columns);
	}

	function createFeedContainer(feed) {
		const container = templates.feedContainer.content.cloneNode(true).firstElementChild;
		container.dataset.feedId = feed.id;
		container.classList.toggle('fontsize-small', feed.currentFontSize === 'small');
		container.classList.toggle('fontsize-large', feed.currentFontSize === 'large');

		const favicon = container.querySelector('.feed-favicon');
		if (feed.favicon) {
			favicon.src = feed.favicon;
			favicon.style.display = '';
		} else {
			favicon.style.display = 'none';
		}
		container.querySelector('.feed-title').textContent = feed.name;
		
		const contentDiv = container.querySelector('.dashboard-container-content');
		if (feed.entries && Array.isArray(feed.entries) && feed.entries.length > 0 && !feed.entries.error) {
			const ul = document.createElement('ul');
			feed.entries.forEach(entry => {
				const li = document.createElement('li');
				li.className = 'entry-item';
				li.innerHTML = `<div class="entry-main"><a href="${entry.link}" target="_blank" rel="noopener noreferrer" class="entry-title">${entry.title || '(No title)'}</a><span class="entry-snippet">${entry.snippet}</span></div><span class="entry-date" title="${entry.dateFull}">${entry.dateShort}</span>`;
				ul.appendChild(li);
			});
			contentDiv.appendChild(ul);
		} else {
			contentDiv.innerHTML = `<p class="no-entries">${feed.entries.error || 'No recent articles.'}</p>`;
		}

		// Populate feed settings editor
		const limitSelect = container.querySelector('.feed-limit-select');
		[5, 15, 25].forEach(val => {
			const opt = new Option(val, val, val === feed.currentLimit, val === feed.currentLimit);
			limitSelect.add(opt);
		});
		const fontSelect = container.querySelector('.feed-fontsize-select');
		['small', 'regular', 'large'].forEach(val => {
			const opt = new Option(val.charAt(0).toUpperCase() + val.slice(1), val, val === feed.currentFontSize, val === feed.currentFontSize);
			fontSelect.add(opt);
		});

		return container;
	}

	// --- ACTIONS ---
	function api(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: new URLSearchParams({ ...body, '_csrf': csrfToken }),
		}).then(res => {
			if (!res.ok) throw new Error('Network response was not ok');
			return res.json();
		});
	}

	function activateTab(tabId, persist = true) {
		if (!tabId) return;
		state.activeTabId = tabId;

		tabsContainer.querySelectorAll('.dashboard-tab').forEach(t => t.classList.toggle('active', t.dataset.tabId === tabId));
		panelsContainer.querySelectorAll('.dashboard-panel').forEach(p => {
			const isActive = p.id === tabId;
			p.classList.toggle('active', isActive);
			if (isActive && p.querySelector('.dashboard-columns').innerHTML === '') {
				renderTabContent(state.layout.find(t => t.id === tabId));
			}
		});
		if (persist) {
			api(setActiveTabUrl, { tab_id: tabId });
		}
	}

	function initializeSortable(columns) {
		if (typeof Sortable === 'undefined') return;
		columns.forEach(column => {
			new Sortable(column, {
				group: 'dashboard-feeds',
				animation: 150,
				handle: '.dashboard-container-header',
				onEnd: () => {
					const panel = column.closest('.dashboard-panel');
					const layout = {};
					panel.querySelectorAll('.dashboard-column').forEach(col => {
						layout[col.dataset.columnId] = Array.from(col.querySelectorAll('.dashboard-container')).map(c => c.dataset.feedId);
					});
					api(saveLayoutUrl, { layout: JSON.stringify(layout), tab_id: panel.id });
				}
			});
		});
	}

	// --- EVENT LISTENERS ---
	function setupEventListeners() {
		// Main delegated listener for the whole dashboard
		dashboardView.addEventListener('click', e => {
			// Tab Management
			const addButton = e.target.closest('.tab-add-button');
			if (addButton) {
				api(tabActionUrl, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						render();
						activateTab(data.new_tab.id);
					}
				});
				return;
			}
			
			const tabLink = e.target.closest('.dashboard-tab');
			if (tabLink && !e.target.closest('.tab-settings-button, .tab-settings-menu')) {
				activateTab(tabLink.dataset.tabId);
				return;
			}

			const settingsButton = e.target.closest('.tab-settings-button');
			if (settingsButton) {
				const menu = settingsButton.nextElementSibling;
				menu.classList.toggle('active');
				e.stopPropagation();
				return;
			}

			const deleteButton = e.target.closest('.tab-action-delete');
			if (deleteButton) {
				const tabId = deleteButton.closest('.dashboard-tab').dataset.tabId;
				if (confirm('Are you sure you want to delete this tab? Feeds on it will be moved to your first tab.')) {
					api(tabActionUrl, { operation: 'delete', tab_id: tabId }).then(data => {
						if (data.status === 'success') {
							state.layout = data.new_layout;
							render();
						}
					});
				}
				return;
			}

			const columnsButton = e.target.closest('[data-columns]');
			if (columnsButton) {
				const numCols = columnsButton.dataset.columns;
				const tabId = columnsButton.closest('.dashboard-tab').dataset.tabId;
				api(tabActionUrl, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
					if (data.status === 'success') {
						state.layout = data.new_layout;
						const tabData = state.layout.find(t => t.id === tabId);
						renderTabContent(tabData);
					}
				});
				return;
			}
			
			// Hide open menus if clicking elsewhere
			if (!e.target.closest('.tab-settings-menu')) {
				document.querySelectorAll('.tab-settings-menu.active').forEach(m => m.classList.remove('active'));
			}
			if (!e.target.closest('.feed-settings-editor')) {
				document.querySelectorAll('.feed-settings-editor.active').forEach(ed => ed.classList.remove('active'));
			}

			// Per-Feed Settings
			const feedSettingsButton = e.target.closest('.feed-settings-button');
			if (feedSettingsButton) {
				const editor = feedSettingsButton.nextElementSibling;
				editor.classList.toggle('active');
				e.stopPropagation();
				return;
			}
			
			const saveFeedSettingsButton = e.target.closest('.feed-settings-save');
			if (saveFeedSettingsButton) {
				const editor = saveFeedSettingsButton.closest('.feed-settings-editor');
				const container = editor.closest('.dashboard-container');
				const feedId = container.dataset.feedId;
				const limit = editor.querySelector('.feed-limit-select').value;
				const fontSize = editor.querySelector('.feed-fontsize-select').value;
				
				api(saveFeedSettingsUrl, { feed_id: feedId, limit, font_size: fontSize }).then(data => {
					if (data.status === 'success') {
						editor.classList.remove('active');
						const oldLimit = state.feeds[feedId].currentLimit;
						state.feeds[feedId].currentLimit = parseInt(limit, 10);
						state.feeds[feedId].currentFontSize = fontSize;

						if (oldLimit !== parseInt(limit, 10)) {
							location.reload();
						} else {
							container.className = 'dashboard-container'; // Reset classes
							if (fontSize !== 'regular') {
								container.classList.add('fontsize-' + fontSize);
							}
						}
					}
				});
				return;
			}
			
			const cancelFeedSettingsButton = e.target.closest('.feed-settings-cancel');
			if (cancelFeedSettingsButton) {
				cancelFeedSettingsButton.closest('.feed-settings-editor').classList.remove('active');
			}
		});

		tabsContainer.addEventListener('dblclick', e => {
			const tabNameSpan = e.target.closest('.tab-name');
			if (!tabNameSpan) return;
			
			const tabElement = tabNameSpan.closest('.dashboard-tab');
			if (!tabElement) return;

			const tabId = tabElement.dataset.tabId;
			const oldName = tabNameSpan.textContent;
			const input = document.createElement('input');
			input.type = 'text';
			input.className = 'tab-name-input';
			input.value = oldName;
			
			const saveName = () => {
				const newName = input.value.trim();
				tabNameSpan.textContent = oldName;
				input.replaceWith(tabNameSpan);

				if (newName && newName !== oldName) {
					api(tabActionUrl, { operation: 'rename', tab_id: tabId, value: newName }).then(data => {
						if (data.status === 'success') {
							tabNameSpan.textContent = newName;
						}
					});
				}
			};
			
			input.addEventListener('blur', saveName);
			input.addEventListener('keydown', e => { if (e.key === 'Enter') saveName(); if (e.key === 'Escape') { input.value = oldName; saveName(); } });
			tabNameSpan.replaceWith(input);
			input.focus();
			input.select();
		});
	}

	// --- INITIALIZATION ---
	const cacheBust = '_=' + Date.now();
	fetch(getLayoutUrl + (getLayoutUrl.includes('?') ? '&' : '?') + cacheBust)
		.then(res => res.json())
		.then(data => {
			state.layout = data.layout;
			state.activeTabId = data.active_tab_id;
			state.feeds = JSON.parse(document.getElementById('feeds-data-script').textContent);
			document.getElementById('feeds-data-script').remove();
			
			render();
			setupEventListeners();
		})
		.catch(error => {
			console.error('DashboardView: Could not initialize.', error);
			dashboardView.innerHTML = '<p>Error loading dashboard. Please check the console and try again.</p>';
		});
}
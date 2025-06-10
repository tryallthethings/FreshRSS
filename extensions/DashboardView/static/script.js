document.addEventListener('freshrss:globalContextLoaded', () => {
	const dashboardView = document.querySelector('.dashboard-view');
	if (dashboardView) {
		initializeDashboard(dashboardView);
	}
});

function initializeDashboard(dashboardView) {
	// --- STATE ---
	let state = { layout: [], feeds: {}, activeTabId: null, allPlacedFeedIds: new Set() };

	// --- DOM & CONFIG ---
	const { getLayoutUrl, saveLayoutUrl, tabActionUrl, setActiveTabUrl, csrfToken, saveFeedSettingsUrl, moveFeedUrl } = dashboardView.dataset;
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
        
        const settingsButton = link.querySelector('.tab-settings-button');
        if (settingsButton) {
            settingsButton.innerHTML = '&#x25BC;'; // Downward-pointing triangle
        }

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

		Object.entries(tab.columns).forEach(([colId, feedIds]) => {
			const colIndex = parseInt(colId.replace('col', ''), 10) - 1;
			if (columns[colIndex]) {
				feedIds.forEach(feedId => {
					const feedData = state.feeds[feedId];
					if (feedData) {
						columns[colIndex].appendChild(createFeedContainer(feedData, tab.id));
					}
				});
			}
		});

		const isFirstTab = state.layout.length > 0 && state.layout[0].id === tab.id;
		if (isFirstTab) {
			Object.values(state.feeds).forEach(feedData => {
				if (!state.allPlacedFeedIds.has(String(feedData.id))) {
					columns[0].appendChild(createFeedContainer(feedData, tab.id));
				}
			});
		}

		initializeSortable(columns);
	}

	function createFeedContainer(feed, sourceTabId) {
		const container = templates.feedContainer.content.cloneNode(true).firstElementChild;
		container.dataset.feedId = feed.id;
        container.dataset.sourceTabId = sourceTabId;
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

		const editor = container.querySelector('.feed-settings-editor');
		const limitSelect = editor.querySelector('.feed-limit-select');
		[5, 15, 25].forEach(val => {
			const opt = new Option(val, val, val === feed.currentLimit, val === feed.currentLimit);
			limitSelect.add(opt);
		});
		const fontSelect = editor.querySelector('.feed-fontsize-select');
		['small', 'regular', 'large'].forEach(val => {
			const opt = new Option(val.charAt(0).toUpperCase() + val.slice(1), val, val === feed.currentFontSize, val === feed.currentFontSize);
			fontSelect.add(opt);
		});
        
        const otherTabs = state.layout.filter(t => t.id !== sourceTabId);
        if (otherTabs.length > 0) {
            const moveDiv = document.createElement('div');
            moveDiv.className = 'feed-move-to';
            moveDiv.innerHTML = '<label>Move to:</label>';
            const ul = document.createElement('ul');
            ul.className = 'feed-move-to-list';
            otherTabs.forEach(tab => {
                const li = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.targetTabId = tab.id;
                button.textContent = tab.name;
                li.appendChild(button);
                ul.appendChild(li);
            });
            moveDiv.appendChild(ul);
            editor.appendChild(moveDiv);
        }

		return container;
	}

	// --- ACTIONS ---
	function api(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: new URLSearchParams({ ...body, '_csrf': csrfToken }),
		}).then(res => {
			if (!res.ok) throw new Error(`Network response was not ok (${res.status})`);
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
			api(setActiveTabUrl, { tab_id: tabId }).catch(console.error);
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
					const tabId = panel.id;
					const layoutData = {};
					panel.querySelectorAll('.dashboard-column').forEach(col => {
						const colId = col.dataset.columnId;
						layoutData[colId] = Array.from(col.querySelectorAll('.dashboard-container')).map(c => c.dataset.feedId);
					});

                    const tab = state.layout.find(t => t.id === tabId);
                    if (tab) tab.columns = layoutData;

					api(saveLayoutUrl, { layout: JSON.stringify(layoutData), tab_id: tabId }).catch(console.error);
				}
			});
		});
	}

	// --- EVENT LISTENERS ---
	function setupEventListeners() {
		dashboardView.addEventListener('click', e => {
			if (e.target.closest('.tab-add-button')) {
				api(tabActionUrl, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						render();
						activateTab(data.new_tab.id);
					}
				}).catch(console.error);
				return;
			}
			
			const tabLink = e.target.closest('.dashboard-tab');
			if (tabLink && !e.target.closest('.tab-settings-button, .tab-settings-menu')) {
				activateTab(tabLink.dataset.tabId);
				return;
			}

			if (e.target.closest('.tab-settings-button')) {
				const menu = e.target.closest('.tab-settings-button').nextElementSibling;
				menu.classList.toggle('active');
				e.stopPropagation();
				return;
			}

			if (e.target.closest('.tab-action-delete')) {
				const tabId = e.target.closest('.dashboard-tab').dataset.tabId;
				if (confirm('Are you sure you want to delete this tab? Feeds on it will be moved to your first tab.')) {
					api(tabActionUrl, { operation: 'delete', tab_id: tabId }).then(data => {
						if (data.status === 'success') {
							state.layout = data.new_layout;
                            state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
							render();
						}
					}).catch(console.error);
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
						state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
						const tabData = state.layout.find(t => t.id === tabId);
						renderTabContent(tabData);
					}
				}).catch(console.error);
				return;
			}
			
			if (e.target.closest('.feed-settings-button')) {
				const editor = e.target.closest('.feed-settings-button').nextElementSibling;
				editor.classList.toggle('active');
				e.stopPropagation();
				return;
			}

            const moveButton = e.target.closest('.feed-move-to-list button');
            if (moveButton) {
                const container = moveButton.closest('.dashboard-container');
                const feedId = container.dataset.feedId;
                const sourceTabId = container.dataset.sourceTabId;
                const targetTabId = moveButton.dataset.targetTabId;

                api(moveFeedUrl, { feed_id: feedId, source_tab_id: sourceTabId, target_tab_id: targetTabId })
                .then(data => {
                    if (data.status === 'success') {
                        state.layout = data.new_layout;
						state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));

						const sourceTab = state.layout.find(t => t.id === sourceTabId) || {id: sourceTabId, num_columns: 3, columns: {}}; // Fallback for re-render
						const targetTab = state.layout.find(t => t.id === targetTabId);
						
						document.getElementById(sourceTabId).querySelector('.dashboard-columns').innerHTML = '';
						renderTabContent(sourceTab);
						
						if (targetTab) {
							document.getElementById(targetTabId).querySelector('.dashboard-columns').innerHTML = '';
							renderTabContent(targetTab);
						}
                    }
                }).catch(console.error);
                return;
            }
			
			if (e.target.closest('.feed-settings-save')) {
				const editor = e.target.closest('.feed-settings-editor');
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
							container.className = 'dashboard-container';
							container.classList.toggle('fontsize-small', fontSize === 'small');
							container.classList.toggle('fontsize-large', fontSize === 'large');
						}
					}
				}).catch(console.error);
				return;
			}
			
			if (e.target.closest('.feed-settings-cancel')) {
				e.target.closest('.feed-settings-editor').classList.remove('active');
				return;
			}

			if (!e.target.closest('.tab-settings-menu')) {
				document.querySelectorAll('.tab-settings-menu.active').forEach(m => m.classList.remove('active'));
			}
			if (!e.target.closest('.feed-settings-editor')) {
				document.querySelectorAll('.feed-settings-editor.active').forEach(ed => ed.classList.remove('active'));
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
                const tabInState = state.layout.find(t => t.id === tabId);
                tabNameSpan.textContent = tabInState ? tabInState.name : oldName;
				input.replaceWith(tabNameSpan);

				if (newName && newName !== oldName) {
					api(tabActionUrl, { operation: 'rename', tab_id: tabId, value: newName }).then(data => {
						if (data.status === 'success') {
							tabNameSpan.textContent = newName;
                            if (tabInState) tabInState.name = newName;
						}
					}).catch(console.error);
				}
			};
			
			input.addEventListener('blur', saveName);
			input.addEventListener('keydown', e => { if (e.key === 'Enter') saveName(); if (e.key === 'Escape') { input.value = oldName; input.blur(); } });
			tabNameSpan.replaceWith(input);
			input.focus();
			input.select();
		});
	}

	// --- INITIALIZATION ---
	fetch(getLayoutUrl)
		.then(res => res.json())
		.then(data => {
            state.allPlacedFeedIds = new Set(data.layout.flatMap(t => Object.values(t.columns).flat()).map(String));
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
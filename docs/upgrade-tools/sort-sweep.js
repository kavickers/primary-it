/*
 * Sort/search sweep for every data table in Snipe-IT.
 *
 * Paste into the browser devtools console while logged in as a superuser on
 * the site under test. For each page listed below it finds the bootstrap
 * tables (table[data-url]), then calls each table's API with every sortable
 * column, ascending and descending, plus a search. ORDER BY is where SQL
 * Server most often disagrees with MySQL (ambiguous or repeated columns,
 * aggregate aliases), and the crawler only tries one sort per endpoint.
 *
 * Raise API_THROTTLE_PER_MINUTE in .env first (e.g. 100000): the sweep makes
 * a few thousand requests. Takes ~20 minutes; progress shows in window.__sweep.
 * See docs/upgrading-from-upstream.md.
 */
(async () => {
    const pages = [
        '/hardware', '/licenses', '/accessories', '/consumables', '/components', '/users', '/kits', '/models',
        '/categories', '/manufacturers', '/suppliers', '/locations', '/departments', '/companies', '/depreciations',
        '/statuslabels', '/fields', '/maintenances', '/admin/groups', '/requests', '/admin/maintenance-types',
        '/reports/activity', '/reports/audit', '/reports/licenses', '/reports/accessories', '/reports/depreciation',
        '/reports/unaccepted_assets', '/reports/maintenances',
        '/hardware/1', '/users/1', '/locations/1', '/licenses/1', '/accessories/1', '/consumables/1', '/components/1',
        '/models/1', '/companies/1', '/departments/1', '/suppliers/1', '/manufacturers/1', '/categories/1',
        '/statuslabels/1', '/kits/1', '/account/view-assets', '/account/requestable', '/account/accept',
        '/admin/login-attempts', '/admin/oauth', '/admin/adapters',
    ];
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
    };
    const sweep = window.__sweep = { done: false, page: '', tables: 0, calls: 0, failures: [] };
    const seen = new Set();

    const check = async (url, label) => {
        sweep.calls++;
        const response = await fetch(url, { headers });
        if (response.status !== 200) {
            const body = await response.text();
            const sqlError = (body.match(/SQLSTATE[^"]{0,200}/) || [])[0];
            sweep.failures.push({ status: response.status, endpoint: url.pathname, request: label, error: sqlError || body.slice(0, 200) });
        }
    };

    for (const page of pages) {
        sweep.page = page;
        const html = await (await fetch(page)).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');

        for (const table of doc.querySelectorAll('table[data-url]')) {
            const dataUrl = table.getAttribute('data-url');
            const key = dataUrl.split('?')[0] + '|' + page;
            if (seen.has(key)) {
                continue;
            }
            seen.add(key);
            sweep.tables++;

            let columns = [];
            try {
                columns = eval(table.getAttribute('data-columns') || '[]');
            } catch (e) {
                // Column definitions that need page scripts; fall back to the header row.
            }
            if (!Array.isArray(columns) || !columns.length) {
                columns = [...table.querySelectorAll('th[data-field]')].map((th) => ({
                    field: th.getAttribute('data-field'),
                    sortable: th.getAttribute('data-sortable') !== 'false',
                }));
            }
            const fields = columns
                .filter((c) => c && c.field && c.sortable !== false && !['checkbox', 'actions'].includes(c.field))
                .map((c) => c.field);

            for (const field of [null, ...fields]) {
                for (const order of ['asc', 'desc']) {
                    const url = new URL(dataUrl, location.origin);
                    url.searchParams.set('limit', '5');
                    url.searchParams.set('offset', '0');
                    url.searchParams.set('order', order);
                    if (field) {
                        url.searchParams.set('sort', field);
                    }
                    await check(url, `sort=${field || '(default)'} ${order}`);
                }
            }

            const searchUrl = new URL(dataUrl, location.origin);
            searchUrl.searchParams.set('search', 'a');
            searchUrl.searchParams.set('limit', '5');
            await check(searchUrl, 'search=a');
        }
    }

    sweep.done = true;
    console.log(`Sort sweep: ${sweep.tables} tables, ${sweep.calls} requests, ${sweep.failures.length} failures`);
    console.table(sweep.failures);
})();

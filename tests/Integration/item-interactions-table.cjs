const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const [url, assets] = process.argv.slice(2);
const scripts = new Map([
    ['https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js', 'jquery.js'],
    ['https://cdn.datatables.net/1.13.1/js/jquery.dataTables.js', 'datatables.js'],
]);

async function verify() {
    const browser = await chromium.launch({
        executablePath: process.env.KEEPLORE_TEST_CHROMIUM || undefined,
    });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        await page.route('**/*', route => {
            const script = scripts.get(route.request().url());
            return script
                ? route.fulfill({ path: path.join(assets, script) })
                : route.fulfill({ status: 204 });
        });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));

        for (const attributes of ['no', 'yes']) {
            for (const online of ['no', 'yes']) {
                for (const duplicates of ['no', 'yes']) {
                    const layout = `attributes ${attributes}, hide online ${online}, hide duplicates ${duplicates}`;
                    const form = new URLSearchParams({
                        'type[0]': '1',
                        csrf_token: 'fixture-token',
                        showAttributes: attributes,
                        hide_online_setting: online,
                        hide_duplicate_group_settings: duplicates,
                    });
                    await page.route(url, async route => {
                        const response = await route.fetch({
                            method: 'POST',
                            headers: {
                                ...route.request().headers(),
                                'X-Test-Viewer': 'owner',
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            postData: form.toString(),
                            timeout: 5000,
                        });
                        await route.fulfill({ response });
                    }, { times: 1 });
                    const response = await page.goto(url, { timeout: 5000 });
                    assert.equal(response.status(), 200, layout);

                    const order = await page.evaluate(() => {
                        if (!window.jQuery?.fn.dataTable?.isDataTable('#uses')) {
                            return null;
                        }
                        return window.jQuery('#uses').DataTable().order();
                    });
                    assert.deepEqual(errors, [], layout);
                    assert.equal(await page.locator('#uses_filter input').count(), 1, layout);
                    const expected = online === 'yes' || duplicates === 'yes'
                        ? (attributes === 'yes' ? [[5, 'desc'], [0, 'desc']] : [[0, 'desc']])
                        : [[0, 'desc'], [2, 'asc']];
                    assert.deepEqual(order, expected, layout);
                }
            }
        }
    } finally {
        await browser.close();
    }
}

verify().catch(error => {
    console.error(error);
    process.exitCode = 1;
});

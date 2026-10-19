"""Request every GET route of a running Snipe-IT site and report server errors.

Logs in, substitutes 1 for every route parameter, and also exercises search,
sort and paging on API index endpoints. Anything answering 5xx is printed and
saved. See docs/upgrading-from-upstream.md.

Usage:
    php artisan route:list --json --method=GET > routes.json
    CRAWL_USER=admin CRAWL_PASS=... python crawl.py http://127.0.0.1:8089 routes.json crawl.json

Run it against a test database with some data in it: requests for record 1
only find bugs if record 1 exists.
"""
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE, ROUTES, OUT = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
SKIP = re.compile(r'^(logout|setup|_debugbar|_ignition|livewire|sanctum|oauth|up$|health|telescope|two-factor|password/reset|.*/export.*|.*backups.*/download|.*/delete.*|.*restore.*|.*purge.*)')

jar = http.cookiejar.CookieJar()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)


def req(method, path, data=None, api=False):
    headers = {'User-Agent': 'smoke-crawler'}
    if api:
        headers.update({'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
        for c in jar:
            if c.name == 'XSRF-TOKEN':
                headers['X-XSRF-TOKEN'] = urllib.parse.unquote(c.value)
    body = urllib.parse.urlencode(data).encode() if data else None
    r = urllib.request.Request(BASE + '/' + path.lstrip('/'), data=body, headers=headers, method=method)
    try:
        resp = opener.open(r, timeout=120)
        return resp.status, resp.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


status, html = req('GET', '/login')
token = re.search(r'name="_token"\s+value="([^"]+)"', html) or re.search(r'value="([^"]+)"\s+name="_token"', html)
status, _ = req('POST', '/login', {'_token': token.group(1), 'username': os.environ['CRAWL_USER'], 'password': os.environ['CRAWL_PASS']})
status, html = req('GET', '/')
assert status == 200 and 'logout' in html.lower(), f'login failed: {status}'

routes = json.load(open(ROUTES))
uris = sorted({r['uri'] for r in routes if 'GET' in r['method']})
targets = []
for uri in uris:
    if SKIP.match(uri):
        continue
    path = re.sub(r'\{[^}]+\}', '1', uri)
    api = uri.startswith('api/')
    targets.append((path, api))
    # Exercise search/sort/filter SQL on API index endpoints
    if api and '{' not in uri:
        targets.append((path + '?limit=5&search=a&sort=created_at&order=desc', True))
        targets.append((path + '?limit=5&offset=5&sort=name&order=asc', True))
        for sort in ('remaining', 'percent_remaining'):
            targets.append((path + f'?limit=5&sort={sort}&order=desc', True))

results = []
for path, api in targets:
    status, body = req('GET', path, api=api)
    err = ''
    if status >= 500:
        m = re.search(r'"message":\s*"((?:[^"\\]|\\.)*)"', body) or re.search(r'<title>(.*?)</title>', body, re.S)
        err = (m.group(1) if m else body[:300]).strip()[:600]
    results.append({'path': path, 'status': status, 'error': err})
    print(status, path, err[:160], flush=True)

json.dump(results, open(OUT, 'w'), indent=1)
bad = [r for r in results if r['status'] >= 500]
print(f'\n{len(results)} requests, {len(bad)} server errors')

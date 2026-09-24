# phox/browser-agent

Probably the fastest goal-driven browser agent for PHP.

Give it a URL and a goal in plain language. It drives a remote Chrome over the Chrome DevTools
Protocol, picks each action from the controls on the page in front of it, and returns what it did,
evidence of what it found, and the page's console errors and failed requests.

It runs against any CDP endpoint. Cloudflare Browser Run is the tested host: several processes can
share one warm browser session, each in its own browser context.

The package is under construction.

## Requirements

- PHP 8.3
- A CDP endpoint, for example Cloudflare Browser Run
- A TypeSafe API key for action decisions

## Development

```bash
composer install
composer test
composer lint
```

Tests run offline and never call a browser host or a model API.

## Licence

MIT

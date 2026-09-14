# Jean-Donald Roselin's PHP Openapi Generator

Turn an OpenAPI spec into a ready-to-use PHP client, autoloaded straight from your project.

```bash
composer require --dev jeandonaldroselin/php-openapi-generator
```

## Quick start

**1. Describe your client** in `openapi-generator.json` at your project root:

```json
{
    "clients": [
        {
            "name": "billing",
            "input_spec": "https://api.example.com/openapi.yaml",
            "package_name": "acme/billing-client",
            "additional_properties": {
                "invokerPackage": "Acme\\Billing\\Client"
            }
        }
    ]
}
```

**2. Generate it:**

```bash
vendor/bin/generate-client
```

That's it — the client is generated into `.generated/billing/`, autoloaded via your project's own
`composer.json`, ready to use:

```php
use Acme\Billing\Client\Api\InvoicesApi;
```

Add `.generated/` to your project's `.gitignore` — it's a build artifact regenerated from your
spec(s), not something to commit.

This also registers a `post-install-cmd`/`post-update-cmd` script, so any future `composer
install` — a teammate's machine, CI, a Docker build — regenerates the client automatically. A
plain `composer install` on a completely fresh checkout just works, no manual step needed.

Need several clients? Add more entries to the `clients` array — one command generates and wires
up all of them. Settings like `generator_name`, `openapi_generator_version`,
`additional_properties` and `generated_path` can be set once at the root of the file and
overridden per client (see [EXPLANATIONS.md](EXPLANATIONS.md)).

## Options

`--config=path.json` · `--client=name` · `--generator-version=X` · `--skip-install` ·
`--force-download` · `--force` (`-f`) · `--no-scripts`

---

Want to know what happens under the hood (caching, namespace conflicts, project-wide config,
etc.)? Everything is explained in [EXPLANATIONS.md](EXPLANATIONS.md).

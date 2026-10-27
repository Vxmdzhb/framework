# astrx/framework

The foundation of [AstrX](https://github.com/Vxmdzhb/astrx), a framework for
server-rendered sites that work without JavaScript:

- **Result** — typed results and diagnostics (`Result<T>`, `DiagnosticsCollector`).
- **Config** — PHP-array configuration with layered defaults and `#[InjectConfig]` setters.
- **Injector** — constructor injection with explicit shared instances.
- **Http**, **Routing** — requests, responses, uploads and URL generation.
- **Template** — a Mustache-like engine compiled to PHP, with a content-addressed cache.
- **I18n** — translation catalogs and diagnostic rendering.
- **Session**, **Csrf** — encrypted sessions, POST/redirect/GET, CSRF tokens.
- **Module**, **Kernel** — the module contract, extension points and controllers.
- **ErrorHandler**, **Pagination**, **Support**.

It depends on no other package. `resources/` holds its diagnostic messages
(en, it) and its default configuration.

`examples/minimal` in the [AstrX repository](https://github.com/Vxmdzhb/astrx) is an application built on this
package alone. The [architecture guide](https://github.com/Vxmdzhb/astrx/blob/main/docs/architecture.md)
describes the layers.

AstrX isn't on Packagist and doesn't tag versions: you install from each
repository's `main`, and your `composer.lock` pins the exact commit. Tell
Composer where to find the package:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/framework" }
    ]
}
```

Then:

```sh
composer require astrx/framework:dev-main
```

Issues and pull requests are welcome here. [AstrX](https://github.com/Vxmdzhb/astrx)
is the site that puts all the packages together.

## License

Released under the [Do Whatever License](LICENSE), with a code of conduct
in the same spirit: [CODE_OF_CONDUCT](CODE_OF_CONDUCT).

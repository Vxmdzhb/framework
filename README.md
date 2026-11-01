# astrx/framework

> **Archived.** This package is now one package per component: [`astrx/result`](https://github.com/Vxmdzhb/result), [`astrx/support`](https://github.com/Vxmdzhb/support), [`astrx/config`](https://github.com/Vxmdzhb/config), [`astrx/injector`](https://github.com/Vxmdzhb/injector), [`astrx/http`](https://github.com/Vxmdzhb/http), [`astrx/routing`](https://github.com/Vxmdzhb/routing), [`astrx/i18n`](https://github.com/Vxmdzhb/i18n), [`astrx/error-handler`](https://github.com/Vxmdzhb/error-handler), [`astrx/template`](https://github.com/Vxmdzhb/template), [`astrx/session`](https://github.com/Vxmdzhb/session), [`astrx/csrf`](https://github.com/Vxmdzhb/csrf), [`astrx/module`](https://github.com/Vxmdzhb/module), [`astrx/pagination`](https://github.com/Vxmdzhb/pagination), [`astrx/kernel`](https://github.com/Vxmdzhb/kernel).
> Each one has its own repository, with its part of this repository's
> history. Require the ones your code uses instead of `astrx/framework`; existing
> lockfiles that pin a commit of this repository keep working.

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

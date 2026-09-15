# Trace-It QR for PHP 7.4

The PHP 7.4 build of [`veriteit/trace-it-qr`](https://github.com/VeriteIT/Trace-it-Composer-Package).
Same package, same behaviour, compiled down to run on 7.4.

> ### This code is generated. Do not edit it.
>
> Every file here is produced from the 8.1 source by `tools/build-php74.php`, which lives
> in this repository and reads the 8.1 package checked out beside it. An edit made here
> is lost the next time the build runs, and it silently turns this into a fork — which
> is the one thing the generator exists to prevent. **Fix it in the 8.1 source and
> regenerate.**

---

## Use the 8.1 package if you can

This build exists for one reason: a CMS stuck on PHP 7.4. If your application runs
PHP 8.1 or newer, install [`veriteit/trace-it-qr`](https://github.com/VeriteIT/Trace-it-Composer-Package)
instead. It is the same code with stronger guarantees, and it is what we develop and
test against first.

**PHP 7.4 reached end of life in November 2022** and has received no security patches
since. That is worth raising with whoever owns the server, separately from this
integration.

---

## Install

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/VeriteIT/Trace-it-qr-php74" }
  ]
}
```

```bash
composer require veriteit/trace-it-qr-php74:^1.0
```

**PHP 7.4+, `ext-curl`, `ext-json`, `ext-gd`.** All required. `ext-gd` is what draws
the code into the photo, so Composer refuses to install without it.

Everything else — configuration, the publish hook, the template changes, the composite
endpoint, badge layout — is identical to the 8.1 package. Follow
[its integration guide](https://github.com/VeriteIT/Trace-it-Composer-Package#readme),
changing only the `composer require` line above. `PACKAGE-REFERENCE.md` here is the
same reference, carried across unchanged.

---

## What differs from the 8.1 build

Two things, both consequences of what 7.4 lacks:

- **`readonly` is gone** — 7.4 has no such modifier, so properties that the 8.1 build
  refuses to reassign at compile time are merely conventional here. Nothing in the
  package relies on callers respecting it, so behaviour is unchanged; but the two
  builds are not identical in what they *forbid*, and that is worth knowing rather
  than discovering.

- **`Stringable` is polyfilled** — the interface arrived in 8.0 and `PostId` implements
  it. `src/polyfill.php` declares it, along with `str_contains`, `str_starts_with` and
  `str_ends_with`. All four are guarded, so the file is harmless if it is ever loaded
  on PHP 8. Composer loads it through its `files` autoload; if you are not using
  Composer, `require` it before anything else.

Everything else — method signatures, return values, error codes, badge geometry — is
the same, and is checked rather than assumed. See below.

---

## How this build is verified

Three layers, because the first two are not enough on their own:

- **The output carries no PHP 8 that the transform missed** — the generator re-reads every
  file it has just written, parses it with `nikic/php-parser` pinned to 7.4, and then walks
  the syntax tree for constructs that exist only in PHP 8. The build fails, loudly, if
  either check finds anything.

  The grammar parse on its own would not be worth much, and it is worth saying why. That
  parser rejects `readonly`, `match` and `enum`, but it accepts promoted constructor
  parameters, named arguments, union types, nullsafe calls, non-capturing `catch` and `new`
  in an initialiser — seven of the nine transforms could regress without it objecting. The
  tree walk is what covers those. It was confirmed by disabling a transform on purpose and
  checking that the build refused to complete.

- **It behaves identically to the 8.1 build** — the generated code is also valid PHP 8,
  so both builds run the same 63-assertion dump and the output is compared byte for
  byte. That covers badge geometry across 30 image-size and aspect-ratio combinations
  including the degenerate 400×120 and 90×1200 cases, every corner, every layout
  override, post ID validation across 13 inputs, and a full `Code` round trip.

- **It runs on a real PHP 7.4.33 interpreter** — lint plus the behavioural dump plus
  the whole compositing path with `ext-gd`: fetch, decode, plan, draw, encode, across
  five image sizes and four corners, with the SSRF host allowlist confirmed to still
  refuse `169.254.169.254` and `file://`.

That third layer is not redundant. It caught two faults the first two missed, both of
which would have shipped: `new Layout()` as a parameter default, which the 7.4 grammar
accepts and the 7.4 compiler rejects; and `PostId implements \Stringable`, which is a
fatal error at class-load time rather than a syntax error.

---

## Licence

Proprietary — see [LICENSE](LICENSE). Licensed for use by organisations with a current
Trace-It agreement. Your API key is confidential and non-transferable.

Questions: [trace-it.io](https://trace-it.io)

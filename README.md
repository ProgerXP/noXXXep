# noXXXep - The Insideous Bug Tracker

Maintaining development tasks must be similar to maintaining documentation - as part of the source code.

* Keep tasks up to date with code and subject to version control system.
* Bind tasks into context of code. Why write a description if the nearby snippet is worth a thousand words?
* Use `grep`, find & replace, log, diff, blame - all the tools that we love.

**noXXXep** itself:

* Supports task priority, tags, groups (blockers), inter-links and custom options (`key=value` and simplified JSON).
* Works with any programming or human language and document type (even binary).
* Requires zero configuration and dependencies sans vanilla PHP 7+.
* Works without JavaScript, if you so desire.

See **noXXXep** used in HeroWO.js - a JavaScript re-implementation of *Heroes of Might and Magic III*.

https://herowo.game/noXXXep/

## The Syntax

Everything begins with a `XXX` that is not part of any *word* (`\b`).

After it, optional priority follows (by default: `=` normal, `+` `++` high/est, `-` `--` low/est).

After priority go optional `,`-separated tags (any `\w`ord symbols).

Extended syntax starts with `:` following priority (or tags, if specified):

1. Optional `,`-separated groups (`\w`).
2. Optional globally unique task identifier (`\w`), after a space.
3. Optional `key=value` options (`\w`), after a space.
4. Final `:` ending the task declaration. This can be replaced or preceded by `...` to indicate presence of a JSON block.

Examples:

```
case '-statistics':
  throw new Exception('-statistics is not implemented')   // XXX
```

```
$sounds = [
  'schoolOfMagic' => 'LOOPMAGI',    // XXX=check,fix
  'alchemistLab' => 'XXX+AUDIO',
];
```

```
var config = {
  // XXX-: LOG: Split this into separate options for fine-grained log control.
  log: false,
}

...

// XXX=:LOG: Do emit this line even in production (but need to fix LOG first).
if (config.log) { console.log(seed) }
```

## (Optional) Configuration

When `noXXXep.php` is opened in a web browser, it scans files in its own directory, refreshing list of tasks as needed and then presents results to the user. Thus you can drop `noXXXep.php` into your project's root and browse it right away.

`noXXXep.php` can be also `include`'d into a PHP script as a library exposing the `noXXXep` class with configuration and useful methods. It won't do anything unless you ask it, perhaps by calling `takeOver()` on a `new noXXXep()` instance (this is what happens when requested in a browser).

Alternatively, you can configure it without including into your script by creating `noXXXep-config.php` in the current working directory or its parent (this is handy if you have `git clone`'d or `git submodule add`'ed **noXXXep** into your project's directory). `$this` inside that script points to the `noXXXep` instance that is going to serve the request.

Sample configuration (see all available properties at the beginning of `noXXXep.php`):

```
<?php
/* noXXXep/noXXXep-config.php */
$this->title = 'My Hideous Bug Tracker';
$this->readOnly = true;     // use in untrusted environment
$this->rootPath = '../app/';
$this->fileRE = '/\.(php|md)$/ui';
```

**noXXXep** comes with embedded JavaScript and CSS styles. Put your additions to `noXXXep.css` and `noXXXep.js` in the current working directory, or according to your `$mediaPath` prefix.

```
/* noXXXep/noXXXep.css */
.Xt__SomeTag > *,
.Xfilters [name="tag[]"] [value="SomeTag"] {
  background: teal;
}
```

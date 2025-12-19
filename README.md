# heyaikeedo/composer

Aikeedo Composer plugin to register custom installer for Aikeedo plugins/themes.

[https://aikeedo.com/](https://aikeedo.com/)

[@heyaikeedo](https://x.com/heyaikeedo)

## Installation

```bash
composer require heyaikeedo/composer
```

## Features

- Custom installer for `aikeedo-plugin` and `aikeedo-theme` package types
- Automatic copying of public assets to the webroot
- Automatic cleanup of public assets on uninstall
- Support for glob patterns

## Public Files Configuration

Aikeedo plugins can define public files/directories that should be copied to the webroot during installation. This is configured in the package's `composer.json` under `extra.public`.

### Basic Syntax

```json
{
  "extra": {
    "public": [
      "path/to/file.js",
      "path/to/directory",
      {
        "source": "source/path",
        "target": "target/path"
      }
    ]
  }
}
```

### Target Path Resolution (Model A)

The target path follows "Model A" where **target is ALWAYS the final destination path**:

| Target Format    | Description                     | Result                               |
| ---------------- | ------------------------------- | ------------------------------------ |
| `"file.js"`      | Package dir + target            | `public/e/{vendor}/{pkg}/file.js`    |
| `"/file.js"`     | Webroot + target                | `public/file.js`                     |
| `"."`            | Package dir + source basename   | `public/e/{vendor}/{pkg}/{basename}` |
| `"/."` or `"/"`  | Webroot + source basename       | `public/{basename}`                  |
| `null` (omitted) | Package dir + source basename   | `public/e/{vendor}/{pkg}/{basename}` |
| `"dir/*"`        | Glob: contents copied to target | Contents copied to target directory  |

### Examples

#### 1. Legacy String Format (preserves full path)

```json
{
  "extra": {
    "public": ["widget/dist/index.html", "widget/dist/styles.css"]
  }
}
```

Result:

- `widget/dist/index.html` → `public/e/{vendor}/{pkg}/widget/dist/index.html`
- `widget/dist/styles.css` → `public/e/{vendor}/{pkg}/widget/dist/styles.css`

#### 2. Copy to Package Directory (no leading `/`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/sdk.js",
        "target": "sdk.js"
      },
      {
        "source": "assets",
        "target": "static"
      }
    ]
  }
}
```

Result:

- `widget/dist/sdk.js` → `public/e/{vendor}/{pkg}/sdk.js`
- `assets/` → `public/e/{vendor}/{pkg}/static/`

#### 3. Copy to Webroot (leading `/`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/sdk.js",
        "target": "/sdk.js"
      },
      {
        "source": "assets",
        "target": "/static/assets"
      }
    ]
  }
}
```

Result:

- `widget/dist/sdk.js` → `public/sdk.js`
- `assets/` → `public/static/assets/`

#### 4. Using Basename Shortcuts (`.` and `/.`)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist",
        "target": "."
      },
      {
        "source": "widget/dist",
        "target": "/."
      },
      {
        "source": "widget/dist"
      }
    ]
  }
}
```

Result:

- `"."` → `public/e/{vendor}/{pkg}/dist/` (package dir + basename)
- `"/."` → `public/dist/` (webroot + basename)
- No target → `public/e/{vendor}/{pkg}/dist/` (same as `"."`)

#### 5. Glob Patterns (copy contents)

```json
{
  "extra": {
    "public": [
      {
        "source": "widget/dist/*",
        "target": "/assets"
      }
    ]
  }
}
```

Result:

- Contents of `widget/dist/` (e.g., `index.html`, `css/`, `js/`) → `public/assets/`
- Files become `public/assets/index.html`, `public/assets/css/...`, etc.

### Environment Variables

| Variable     | Description         | Default  |
| ------------ | ------------------- | -------- |
| `PUBLIC_DIR` | Custom webroot path | `public` |

## Package Types

This plugin handles the following package types:

- `aikeedo-plugin` - Installed to `extra/extensions/{vendor}/{package}`
- `aikeedo-theme` - Installed to `extra/extensions/{vendor}/{package}`

## License

See [LICENSE](LICENSE) file.

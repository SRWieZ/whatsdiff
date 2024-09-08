# whatsdiff

![GitHub release (with filter)](https://img.shields.io/github/v/release/SRWieZ/whatsdiff)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/SRWieZ/whatsdiff/php)
![Packagist License (custom server)](https://img.shields.io/packagist/l/SRWieZ/whatsdiff)
![GitHub Workflow Status (with event)](https://img.shields.io/github/actions/workflow/status/SRWieZ/whatsdiff/test.yml)


CLI Tool to see what's changed in your project's dependencies

## Installation


Via [Composer](https://getcomposer.org/) global install command
```bash
composer global install srwiez/whatsdiff
```

By [downloading binaries](https://github.com/SRWieZ/whatsdiff/releases/latest) on the latest release, currently only these binaries are compiled on the CI:
- macOS x86_64
- macOS arm64
- linux x86_64
- linux arm64
- windows x64

[//]: # (Coming soon to [Homebrew]&#40;https://brew.sh/&#41;)

[//]: # (Via [Homebrew]&#40;https://brew.sh/&#41; &#40;macOS & Linux&#41;)

[//]: # (```bash)

[//]: # (brew tap srwiez/homebrew-tap)

[//]: # (brew install whatsdiff)

[//]: # (```)

## Usage

Go on your project root directory after a `composer update` and just ask:
```bash
whatsdiff
```

## Testing
This project use [Pest](https://pestphp.com/) for testing.
```bash
composer test
```

## Contribute
This project follows PSR coding style. You can use `composer pint` to apply.

All tests are executed with pest. Use `composer pest`

It's recommended to execute `composer qa` before commiting (alias for executing Pint and Pest)

## Build from sources
This project use [box](https://github.com/box-project/box), [php-static-cli](https://github.com/crazywhalecc/static-php-cli) and [php-micro](https://github.com/dixyes/phpmicro).
A build script has been created to build the project. (tested only on macOS x86_64)

```bash
composer build
```
Then you can build the binary that you can retrieve in `build/bin/`

[//]: # (You can also build it from Github Workflow, or locally on MacOS using [act]&#40;https://github.com/nektos/act&#41;)

[//]: # (```bash)

[//]: # (act -j build-macos-binary -P macos-latest=-self-hosted)

[//]: # (act -j build-linux-binary)

[//]: # (act -j build-linux-arm-binary)

[//]: # (```)
## Roadmap
Pull requests are welcome! Here are some ideas to get you started:
- Analyse composer.lock
- Analyse package-json.lock
- Use Symfony Console for better ui
- Publish on Homebrew 

## Credits

**whatsdiff** was created by Eser DENIZ.

## License

**whatsdiff** PHP is licensed under the MIT License. See LICENSE for more information.
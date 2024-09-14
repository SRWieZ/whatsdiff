# whatsdiff

![GitHub release (with filter)](https://img.shields.io/github/v/release/SRWieZ/whatsdiff)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/SRWieZ/whatsdiff/php)
![Packagist License (custom server)](https://img.shields.io/packagist/l/SRWieZ/whatsdiff)
![GitHub Workflow Status (with event)](https://img.shields.io/github/actions/workflow/status/SRWieZ/whatsdiff/test.yml)

CLI Tool to see what's changed in your project's dependencies

## 🚀 Installation
Via [Composer](https://getcomposer.org/) global require command
```bash
composer global require srwiez/whatsdiff
```

By [downloading binaries](https://github.com/SRWieZ/whatsdiff/releases/latest) on the latest release, currently only these binaries are compiled on the CI:
- macOS x86_64
- macOS arm64
- linux x86_64
- linux arm64
- windows x64

## 📚 Usage

Go on your project root directory after a `composer update` and just ask:
```bash
whatsdiff
```

## 📋 Roadmap
Pull requests are welcome! Here are some ideas to get you started:
- [x] Analyse composer.lock
- [x] Find releases through packagist.com
- [ ] Retrieve changelog with Github API
- [ ] Make a nice TUI
- [ ] Analyse package-json.lock / yarn.lock (javascript)
- [ ] Analyse gradle dependencies (android)
- [ ] Analyse cocoapods dependencies (iOS)
- [ ] Analyse pip dependencies (python)
- [ ] Analyse gem dependencies (ruby)
- [ ] Analyse cargo dependencies (rust)
- [ ] Analyse go.mod dependencies (go)
- [ ] Publish on Homebrew

## 🔧 Contributing
This project follows PSR coding style. You can use `composer pint` to apply.

All tests are executed with pest. Use `composer pest`

It's recommended to execute `composer qa` before commiting (alias for executing Pint and Pest)

### Testing
This project use [Pest](https://pestphp.com/) for testing.
```bash
composer test
```
### Build from sources
This project use [box](https://github.com/box-project/box), [php-static-cli](https://github.com/crazywhalecc/static-php-cli) and [php-micro](https://github.com/dixyes/phpmicro).
A build script has been created to build the project. (tested only on macOS x86_64)

```bash
composer build
```
Then you can build the binary that you can retrieve in `build/bin/`

## 👥 Credits

**whatsdiff** was created by Eser DENIZ.

## 📝 License

**whatsdiff** PHP is licensed under the MIT License. See LICENSE for more information.
#!/bin/bash
set -e

# Initialize default binary name
binary_name="whatsdiff"

# Parse command line arguments
while [[ "$#" -gt 0 ]]; do
    case $1 in
        --name) binary_name="$2"; shift ;; # Get the new binary name
        *) echo "Unknown parameter passed: $1"; exit 1 ;;
    esac
    shift
done

# Removing old build files
rm -rf build/bin/
#rm -rf build/buildroot/
#rm -rf build/downloads/
#rm -rf build/source/
#rm -rf build/static-php-cli/

# Directories
mkdir -p build/bin/

# Build phar file using box and bos.json
composer box compile

# Fetch or update static-php-cli
if [ -d "build/static-php-cli" ]; then
  cd build/static-php-cli/
#  git reset --hard HEAD
  git pull
else
  cd build/
  git clone --depth 1 https://github.com/crazywhalecc/static-php-cli.git
  cd static-php-cli/
fi

# Install dependencies
composer update --no-interaction
chmod +x bin/spc-alpine-docker

# Back to main directory
#cd ../

# Build PHP Micro with only the extensions we need
SPC_USE_ARCH=aarch64 ./bin/spc-alpine-docker download --with-php="8.3" --for-extensions="dom,phar,zlib" --prefer-pre-built
SPC_USE_ARCH=aarch64 ./bin/spc-alpine-docker switch-php-version "8.3"
SPC_USE_ARCH=aarch64 ./bin/spc-alpine-docker build --build-micro "dom,phar,zlib"

# Build binary
cat buildroot/bin/micro.sfx ../bin/whatsdiff.phar > "../bin/$binary_name"
chmod 0755 "../bin/$binary_name"


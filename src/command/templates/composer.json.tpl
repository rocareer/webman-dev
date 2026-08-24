{
  "name": "rocareer/{{NAME}}",
  "type": "library",
  "keywords": ["webman", "radmin", "{{NAME}}"],
  "homepage": "https://rocareer.com",
  "license": "proprietary",
  "description": "{{DESC}}",
  "authors": [
    {
      "name": "albert",
      "email": "albert@rocareer.com",
      "homepage": "https://rocareer.com",
      "role": "Developer"
    }
  ],
  "support": {
    "email": "albert@rocareer.com",
    "source": "https://gitee.com/rocareer/{{UC}}"
  },
  "require": {
    "php": ">=8.1",
    "workerman/webman-framework": "^2.1",
    "rocareer/radmin": "^3.2"
  },
  "autoload": {
    "psr-4": {
      "Rocareer\\{{UC}}\\": "src",
      "app\\": "src/app"
    },
    "files": [
      "src/app/common.php"
    ]
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}

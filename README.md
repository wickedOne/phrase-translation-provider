# phrase-translation-provider
symfony phrase translation provider bridge

[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FwickedOne%2Fphrase-translation-provider%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/wickedOne/phrase-translation-provider/master)
[![codecov](https://codecov.io/gh/wickedOne/phrase-translation-provider/branch/master/graph/badge.svg?token=UHKAVGURP7)](https://codecov.io/gh/wickedOne/phrase-translation-provider)
[![Latest Stable Version](http://poser.pugx.org/wickedone/phrase-translation-provider/v)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![Total Downloads](http://poser.pugx.org/wickedone/phrase-translation-provider/downloads)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![License](http://poser.pugx.org/wickedone/phrase-translation-provider/license)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![PHP Version Require](http://poser.pugx.org/wickedone/phrase-translation-provider/require/php)](https://packagist.org/packages/wickedone/phrase-translation-provider)

## installation
```bash
composer require wickedone/phrase-translation-provider
```

## dsn example
```dotenv
PHRASE_DSN=phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject
```
 
### dsn elements

- `PROJECT_ID`: can be retrieved in phrase from `project settings > API > Project ID`
- `API_TOKEN`: can be created in your [phrase profile settings](https://app.phrase.com/settings/oauth_access_tokens)
- `default`: endpoint, defaults to `api.phrase.com`
### dsn query parameters

- `userAgent`: please read [this](https://developers.phrase.com/api/#overview--identification-via-user-agent) for some examples.

## ❗ curly delimiters for placeholders
at the moment the phrase user interface to goes all bonkers when using curly brackets as delimiters for placeholders.
phrase is aware of this issue and is currently looking into that. until that's resolved, try and not be bothered by it.

## service phrase provider
in your `services.yaml` add the following to enable the phrase provider.
```yaml
Symfony\Component\Translation\Bridge\Phrase\PhraseProviderFactory:
    tags: ['translation.provider_factory']
    arguments:
        $defaultLocale: '%kernel.default_locale%'
        $loader: '@translation.loader.xliff'
        $xliffFileDumper: '@translation.dumper.xliff'
        $cache: '@cache.app'
```
and in your `translations.yaml` you can add:
```yaml
framework:
    translator:
        providers:
            phrase:
                dsn: '%env(PHRASE_DSN)%'
                domains: ~
                locales: ~
```

## locale creation
if you define a locale in your `translation.yaml` which is not configured in your phrase project, it will be automatically created. deletion of locales however, is (currently) not managed by this provider.

## domains as tags
translations will be tagged in phrase with the symfony translation domain they belong to.
currently no feature to map domains to alternative tags is provided (helper to batch add tags to existing translations seems more sensible).

## cache
the read responses from phrase are cached to speed up the read and delete method of this provider.
therefor the factory should be initialised with a PSR-6 compatible cache adapter.

## events
to enable you to perform post-processing on translation values and / or keys, two events are dispatched by this provider class.

### PhraseReadEvent
_after_ reading the catalogue from phrase, the resulting `TranslatorBag` is dispatched in a `PhraseReadEvent` prior to being returned from the read method. 

### PhraseWriteEvent
_before_ writing the catalogue to phrase, the `TranslatorBag` is dispatched in a `PhraseWriteEvent`.
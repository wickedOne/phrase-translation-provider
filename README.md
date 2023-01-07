# phrase-translation-provider

symfony phrase translation provider bridge

[![infection](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FwickedOne%2Fphrase-translation-provider%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/wickedOne/phrase-translation-provider/master)
[![codecov](https://codecov.io/gh/wickedOne/phrase-translation-provider/branch/master/graph/badge.svg?token=UHKAVGURP7)](https://codecov.io/gh/wickedOne/phrase-translation-provider)
[![psalm](https://shepherd.dev/github/wickedOne/phrase-translation-provider/coverage.svg)](https://codecov.io/gh/wickedOne/phrase-translation-provider)
[![stable](http://poser.pugx.org/wickedone/phrase-translation-provider/v)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![downloads](http://poser.pugx.org/wickedone/phrase-translation-provider/downloads)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![license](http://poser.pugx.org/wickedone/phrase-translation-provider/license)](https://packagist.org/packages/wickedone/phrase-translation-provider)
[![php](http://poser.pugx.org/wickedone/phrase-translation-provider/require/php)](https://packagist.org/packages/wickedone/phrase-translation-provider)

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

### required dsn query parameters

- `userAgent`: please read [this](https://developers.phrase.com/api/#overview--identification-via-user-agent) for some examples.

see [fine tuning your phrase api calls](#fine-tuning-your-phrase-api-calls) for additional dsn options

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
check the [wickedone/phrase-translation-bundle](https://github.com/wickedOne/phrase-translation-bundle) if you need help managing your tags in phrase 

## cache

the read responses from phrase are cached to speed up the read and delete method of this provider.
therefor the factory should be initialised with a PSR-6 compatible cache adapter.

## events

to enable you to perform post-processing on translation values and / or keys, two events are dispatched by this provider class.

### PhraseReadEvent

_after_ reading the catalogue from phrase, the resulting `TranslatorBag` is dispatched in a `PhraseReadEvent` prior to being returned from the read method. 

### PhraseWriteEvent

_before_ writing the catalogue to phrase, the `TranslatorBag` is dispatched in a `PhraseWriteEvent`.

## fine tuning your phrase api calls

you can fine tune the read and write methods of this provider by adding query parameters to your dsn configuration.
general usage is `read|write[option_name]=value`

**example:** `phrase://PROJECT_ID:API_TOKEN@default?read[encoding]=UTF-8&write[update_descriptions]=0`

see tables below for available options and, if applicable, their default values.

### read

in order to read translations from phrase the [download locale](https://developers.phrase.com/api/#get-/projects/-project_id-/locales/-id-/download) call is made to the phrase api. this call provides the following options.

| name                              |   type   |   default value    | comment                                                                             |
|-----------------------------------|:--------:|:------------------:|-------------------------------------------------------------------------------------|
| `branch`                          |  string  |                    |                                                                                     |
| `include_empty_translations`      |   bool   |         1          |                                                                                     |
| _`exclude_empty_zero_forms`_      |   bool   |                    |                                                                                     |
| `include_translated_keys`         |   bool   |                    |                                                                                     |
| `keep_notranslate_tags`           |   bool   |                    |                                                                                     |
| `format_options`                  |  array   |  enclose_in_cdata  |                                                                                     |                                                                                     |
| `encoding`                        |  string  |                    |                                                                                     |                                                                                     |
| `skip_unverified_translations`    |   bool   |                    |                                                                                     |                                                                                     |
| `include_unverified_translations` |   bool   |                    |                                                                                     |                                                                                     |
| `use_last_reviewed_version`       |   bool   |                    |                                                                                     |                                                                                     |
| `fallback_locale_enabled`         |   bool   |         0          | when the fallback locale is enabled, caching responses from phrase will be disabled |

### write

in order to write translations to phrase the [upload](https://developers.phrase.com/api/#post-/projects/-project_id-/uploads) call is made to the phrase api. this call provides the following options.  

| name                  |  type  | default value | comment |
|-----------------------|:------:|:-------------:|---------|
| `update_translations` |  bool  |       1       |         |
| `update_descriptions` |  bool  |               |         |
| `skip_upload_tags`    |  bool  |               |         |
| `skip_unverification` |  bool  |               |         |
| `file_encoding`       | string |               |         |
| `locale_mapping`      | array  |               |         |
| `format_options`      | array  |               |         |
| `autotranslate`       |  bool  |               |         |
| `mark_reviewed`       |  bool  |               |         |

# phrase-translation-provider
symfony phrase translation provider bridge

## dsn example
```dotenv
PHRASE_DSN=phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject
```
 
### dsn elements

- `PROJECT_ID`: can be retrieved in phrase from `project settings > API > Project ID`
- `API_TOKEN`: can be created in your [phrase profile settings](https://app.phrase.com/settings/oauth_access_tokens)
- `default`: enpoint, defaults to `api.phrase.com`
### dsn query parameters

- `userAgent`: please read [this](https://developers.phrase.com/api/#overview--identification-via-user-agent) for some examples.

## ❗ blocked key
to prevent your phrase interface to go all bonkers, add `*{{*}}*` as a blocked key to your project. basically ruling out a whole bunch of default symfony validator constraint messages.

at the moment this can't be done programmatically as phrase doesn't provide a way to query exisiting blocked keys and listing them has a limit of 100 blocked keys per response which will potentially endanger your rate limit for projects with large amounts of blocked keys.

## service phrase provider
in your `services.yaml` add the following to enable the phrase provider.
```yaml
    Symfony\Component\Translation\Bridge\Phrase\PhraseProviderFactory:
        tags: ['translation.provider_factory']
        arguments:
            $defaultLocale: '%kernel.default_locale%'
            $loader: '@translation.loader.xliff'
            $xliffFileDumper: '@translation.dumper.xliff'
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

## phrase read event
after reading the catalogue from phrase, the resulting `TranslatorBag` is dispatched in a `PhraseReadEvent` prior to being returned from the provider's read method. this will enable you to perform post-processing on translation values and / or keys if needed.
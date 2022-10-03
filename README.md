Relay-API Bundle README Template
================================

<!--
This should act as a template README.md for a new Relay-API Bundle.
Just remove the parts that are not relevant to your bundle and
replace placeholders like "{{Name}}" with your bundle name and so on.

List of placeholders:
- {{name}}: Name of the bundle in lowercase, like "formalize"
- {{Name}}: Name of the bundle in camel case, like "Formalize"
- {{NAME}}: Name of the bundle in uppercase, like "FORMALIZE"
- {{bundle-path}}: GitLab bundle repository path, like "dbp/formalize/dbp-relay-formalize-bundle"
- {{package-name}}: Name of the bundle for packagist, like "dbp/relay-formalize-bundle"
- {{app-path}}: GitLab repository path of the frontend application, like "dbp/formalize/formalize"
-->

# DbpRelay{{Name}}Bundle

[GitLab](https://gitlab.tugraz.at/{{bundle-path}}) |
[Packagist](https://packagist.org/packages/{{package-name}}) |
[Frontend Application](https://gitlab.tugraz.at/{{app-path}}) |
[{{Name}} Website](https://dbp-demo.tugraz.at/site/software/{{name}}.html)

The {{name}} bundle provides an API for interacting with ...

There is a corresponding frontend application that uses this API at [{{Name}} Frontend Application](https://gitlab.tugraz.at/{{app-path}}).

## Bundle installation

You can install the bundle directly from [packagist.org](https://packagist.org/packages/{{package-name}}).

```bash
composer require {{package-name}}
```

## Integration into the Relay API Server

* Add the bundle to your `config/bundles.php` in front of `DbpRelayCoreBundle`:

```php
...
Dbp\Relay\{{Name}}Bundle\DbpRelay{{Name}}Bundle::class => ['all' => true],
Dbp\Relay\CoreBundle\DbpRelayCoreBundle::class => ['all' => true],
];
```

If you were using the [DBP API Server Template](https://gitlab.tugraz.at/dbp/relay/dbp-relay-server-template)
as template for your Symfony application, then this should have already been generated for you.

* Run `composer install` to clear caches

## Configuration

The bundle has a `database_url` configuration value that you can specify in your
app, either by hard-coding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_{{name}}.yaml` in the app with the following
content:

```yaml
dbp_relay_{{name}}:
  database_url: 'mysql://db:secret@mariadb:3306/db?serverVersion=mariadb-10.3.30'
  # database_url: %env({{NAME}}_DATABASE_URL)%
```

If you were using the [DBP API Server Template](https://gitlab.tugraz.at/dbp/relay/dbp-relay-server-template)
as template for your Symfony application, then the configuration file should have already been generated for you.

For more info on bundle configuration see <https://symfony.com/doc/current/bundles/configuration.html>.

## Development & Testing

* Install dependencies: `composer install`
* Run tests: `composer test`
* Run linters: `composer run lint`
* Run cs-fixer: `composer run cs-fix`

## Bundle dependencies

Don't forget you need to pull down your dependencies in your main application if you are installing packages in a bundle.

```bash
# updates and installs dependencies of {{package-name}}
composer update {{package-name}}
```

## Scripts

### Database migration

Run this script to migrate the database. Run this script after installation of the bundle and
after every update to adapt the database to the new source code.

```bash
php bin/console doctrine:migrations:migrate --em=dbp_relay_{{name}}_bundle
```

## Error codes

### `/{{name}}/submissions`

#### POST

| relay:errorId                       | Status code | Description                                     | relay:errorDetails | Example                          |
|-------------------------------------|-------------|-------------------------------------------------| ------------------ |----------------------------------|
| `{{name}}:submission-not-created`  | 500         | The submission could not be created.            | `message`          | `['message' => 'Error message']` |
| `{{name}}:submission-invalid-json` | 422         | The dataFeedElement doesn't contain valid json. | `message`          |                                  |

### `/{{name}}/submissions/{identifier}`

#### GET

| relay:errorId                    | Status code | Description               | relay:errorDetails | Example |
| -------------------------------- | ----------- | ------------------------- | ------------------ | ------- |
| `{{name}}:submission-not-found` | 404         | Submission was not found. |                    |         |

## Roles

This bundle needs the role `ROLE_SCOPE_{{NAME}}` assigned to the user to get permissions to fetch data.
To create a new submission entry the Symfony role `ROLE_SCOPE_{{NAME}}-POST` is required.

## Events

To extend the behavior of the bundle the following event is registered:

### CreateSubmissionPostEvent

This event allows you to react on submission creations.
You can use this for example to email the submitter of the submission.

An event subscriber receives a `Dbp\Relay\{{Name}}Bundle\Event\CreateSubmissionPostEvent` instance
in a service for example in `src/EventSubscriber/CreateSubmissionSubscriber.php`:

```php
<?php

namespace App\EventSubscriber;

use Dbp\Relay\{{Name}}Bundle\Event\CreateSubmissionPostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CreateSubmissionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CreateSubmissionPostEvent::NAME => 'onPost',
        ];
    }

    public function onPost(CreateSubmissionPostEvent $event)
    {
        $submission = $event->getSubmission();
        $dataFeedElement = $submission->getDataFeedElementDecoded();

        // TODO: extract email address and send email
        $email = $dataFeedElement['email'];
    }
}
```

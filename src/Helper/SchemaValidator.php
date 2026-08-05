<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

/**
 * Validates data against a JSON schema using opis/json-schema.
 *
 * @internal
 */
class SchemaValidator
{
    /**
     * Validates the given data against the JSON schema located at $schemaPath.
     *
     * @return array<string, string> a map of property path to error message, empty if the data is valid
     */
    public static function validateJsonSchemaData(mixed $data, string $schemaPath): array
    {
        $schemaRealPath = realpath($schemaPath);
        if ($schemaRealPath === false) {
            throw new \RuntimeException(sprintf('Schema file not found: %s', $schemaPath));
        }

        $result = self::createJsonSchemaValidator()->validate($data, 'file://'.$schemaRealPath);

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        return $error instanceof ValidationError ? self::collectJsonSchemaValidationMessages($error) : ['$' => 'JSON schema validation failed'];
    }

    private static function createJsonSchemaValidator(): Validator
    {
        $resolver = new SchemaResolver();
        $resolver->registerProtocol('file', function (Uri $uri) {
            $contents = file_get_contents($uri->path());
            if (!is_string($contents)) {
                return null;
            }

            $schemaData = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

            return self::normalizeJsonSchemaForOpis($schemaData, (string) $uri);
        });

        return new Validator(new SchemaLoader(new SchemaParser(), $resolver, true));
    }

    private static function normalizeJsonSchemaForOpis(mixed $value, ?string $baseUri = null, ?string $draftUri = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalizeJsonSchemaForOpis($item, $baseUri, $draftUri);
            }

            return $value;
        }

        if (!is_object($value)) {
            return $value;
        }

        $currentDraftUri = is_string($value->{'$schema'} ?? null) ? rtrim($value->{'$schema'}, '#') : $draftUri;

        $currentBaseUri = $baseUri;
        if (is_string($value->{'$id'} ?? null)) {
            $currentBaseUri = (string) Uri::merge($value->{'$id'}, $baseUri, true);
            $value->{'$id'} = $currentBaseUri;
        } elseif (in_array($currentDraftUri, ['http://json-schema.org/draft-06/schema', 'http://json-schema.org/draft-07/schema'], true) && is_string($value->id ?? null)) {
            $currentBaseUri = (string) Uri::merge($value->id, $baseUri, true);
            $value->{'$id'} = $currentBaseUri;
            unset($value->id);
        }

        if (property_exists($value, 'exclusiveMinimum') && is_bool($value->exclusiveMinimum) && isset($value->minimum) && is_numeric($value->minimum)) {
            if ($value->exclusiveMinimum) {
                $value->exclusiveMinimum = $value->minimum;
                unset($value->minimum);
            } else {
                unset($value->exclusiveMinimum);
            }
        }

        if (property_exists($value, 'exclusiveMaximum') && is_bool($value->exclusiveMaximum) && isset($value->maximum) && is_numeric($value->maximum)) {
            if ($value->exclusiveMaximum) {
                $value->exclusiveMaximum = $value->maximum;
                unset($value->maximum);
            } else {
                unset($value->exclusiveMaximum);
            }
        }

        foreach (get_object_vars($value) as $key => $item) {
            $value->{$key} = self::normalizeJsonSchemaForOpis($item, $currentBaseUri, $currentDraftUri);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function collectJsonSchemaValidationMessages(ValidationError $error): array
    {
        $messages = [];
        self::appendJsonSchemaValidationMessages($error, $messages);

        if ($messages === []) {
            $messages[self::formatJsonSchemaValidationPath($error)] = self::formatJsonSchemaValidationMessage($error);
        }

        return $messages;
    }

    /**
     * @param array<string, string> $messages
     */
    private static function appendJsonSchemaValidationMessages(ValidationError $error, array &$messages): void
    {
        $subErrors = $error->subErrors();
        if ($subErrors !== []) {
            foreach ($subErrors as $subError) {
                self::appendJsonSchemaValidationMessages($subError, $messages);
            }

            return;
        }

        $messages[self::formatJsonSchemaValidationPath($error)] = self::formatJsonSchemaValidationMessage($error);
    }

    private static function formatJsonSchemaValidationPath(ValidationError $error): string
    {
        $path = $error->data()->fullPath();

        if ($path === []) {
            if ($error->keyword() === 'required') {
                $missing = $error->args()['missing'] ?? [];
                if (count($missing) === 1 && is_string($missing[0])) {
                    return $missing[0];
                }
            }

            if ($error->keyword() === 'dependentRequired' && is_string($error->args()['missing'] ?? null)) {
                return $error->args()['missing'];
            }

            return '$';
        }

        $formattedPath = '';
        foreach ($path as $segment) {
            if (is_int($segment)) {
                $formattedPath .= sprintf('[%d]', $segment);
                continue;
            }

            $formattedPath .= $formattedPath === '' ? $segment : '.'.$segment;
        }

        return $formattedPath;
    }

    private static function formatJsonSchemaValidationMessage(ValidationError $error): string
    {
        return match ($error->keyword()) {
            'required' => sprintf(
                'The required properties are missing: %s',
                implode(', ', array_map(static fn (mixed $value): string => (string) $value, $error->args()['missing'] ?? []))
            ),
            'const' => sprintf(
                'The data must match the const value: %s',
                self::stringifyJsonSchemaValidationValue($error->args()['const'] ?? null)
            ),
            default => self::interpolateJsonSchemaValidationMessage($error),
        };
    }

    private static function interpolateJsonSchemaValidationMessage(ValidationError $error): string
    {
        $message = $error->message();

        foreach ($error->args() as $key => $value) {
            $message = str_replace('{'.$key.'}', self::stringifyJsonSchemaValidationValue($value), $message);
        }

        return $message;
    }

    private static function stringifyJsonSchemaValidationValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => self::stringifyJsonSchemaValidationValue($item), $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}

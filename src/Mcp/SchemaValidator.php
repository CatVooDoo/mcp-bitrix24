<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Mcp;

final class SchemaValidator
{
    public static function validate(mixed $value,array $schema,string $path='arguments'): void
    {
        $type=$schema['type'] ?? null;
        $ok=match($type) {
            'object'=>is_array($value) && (!$value || !array_is_list($value)),
            'array'=>is_array($value) && array_is_list($value),
            'integer'=>is_int($value), 'number'=>is_int($value)||is_float($value),
            'string'=>is_string($value), 'boolean'=>is_bool($value), default=>true,
        };
        if (!$ok) { throw new \InvalidArgumentException($path.' must be '.$type,-32602); }
        if (isset($schema['enum']) && !in_array($value,$schema['enum'],true)) { throw new \InvalidArgumentException($path.' is not an allowed value',-32602); }
        if (is_string($value) && (mb_strlen($value)<($schema['minLength'] ?? 0) || mb_strlen($value)>($schema['maxLength'] ?? 1000000))) { throw new \InvalidArgumentException($path.' has invalid length',-32602); }
        if (is_int($value) && ($value<($schema['minimum'] ?? PHP_INT_MIN) || $value>($schema['maximum'] ?? PHP_INT_MAX))) { throw new \InvalidArgumentException($path.' is out of range',-32602); }
        if ($type==='object') {
            foreach ($schema['required'] ?? [] as $key) { if (!array_key_exists($key,$value)) { throw new \InvalidArgumentException($path.'.'.$key.' is required',-32602); } }
            foreach ($value as $key=>$child) {
                if (isset($schema['properties'][$key])) { self::validate($child,$schema['properties'][$key],$path.'.'.$key); }
                elseif (($schema['additionalProperties'] ?? true)===false) { throw new \InvalidArgumentException($path.'.'.$key.' is unknown',-32602); }
            }
        }
        if ($type==='array') {
            if (count($value)>($schema['maxItems'] ?? 1000)) { throw new \InvalidArgumentException($path.' has too many items',-32602); }
            foreach ($value as $i=>$child) { self::validate($child,$schema['items'] ?? [],$path.'.'.$i); }
        }
    }
}

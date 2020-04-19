<?php declare(strict_types=1);

namespace Kcs\MessengerExtra\Adapter\Serializer;

use Kcs\Serializer\DeserializationContext;
use Kcs\Serializer\SerializationContext;

/**
 * This class converts symfony's context array to serializer's own context.
 *
 * @internal
 */
final class ContextConverter
{
    /**
     * Converts to SerializationContext.
     */
    public static function toSerializationContext(array $context): SerializationContext
    {
        $ctx = new SerializationContext();

        if (! empty($context['groups'])) {
            $ctx->setGroups($context['groups']);
        }

        return $ctx;
    }

    /**
     * Converts to DeserializationContext.
     */
    public static function toDeserializationContext(array $context): DeserializationContext
    {
        $ctx = new DeserializationContext();

        if (isset($context['object_to_populate'])) {
            $ctx->setAttribute('target', $context['object_to_populate']);
        }

        if (! empty($context['groups'])) {
            $ctx->setGroups($context['groups']);
        }

        return $ctx;
    }
}

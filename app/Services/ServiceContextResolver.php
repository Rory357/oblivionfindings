<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ServiceContext;

class ServiceContextResolver
{
    /**
     * Resolve the appropriate service context for a client.
     *
     * Priority:
     * 1. Provided context ID (if valid)
     * 2. Client's assigned service context
     * 3. Organization default service context
     * 4. First available active context
     * 5. null
     *
     * @param int|null $clientId The client ID to resolve for
     * @param int|null $providedContextId Optional explicitly provided context ID
     * @return int|null The resolved service context ID
     */
    public function resolveForClient(?int $clientId, ?int $providedContextId = null): ?int
    {
        // If a valid context ID is explicitly provided, use it
        if ($providedContextId && $this->isValidContext($providedContextId)) {
            return $providedContextId;
        }

        // Try to get from client
        if ($clientId) {
            $clientContextId = Client::query()
                ->whereKey($clientId)
                ->value('service_context_id');
            
            if ($clientContextId && $this->isValidContext($clientContextId)) {
                return $clientContextId;
            }
        }

        // Fall back to organization default
        $defaultId = ServiceContext::defaultId();
        if ($defaultId && $this->isValidContext($defaultId)) {
            return $defaultId;
        }

        // Last resort: first active context
        $firstActive = ServiceContext::query()
            ->where('is_active', true)
            ->value('id');

        return $firstActive;
    }

    /**
     * Check if a service context ID is valid and active.
     *
     * @param int $contextId
     * @return bool
     */
    public function isValidContext(int $contextId): bool
    {
        return ServiceContext::query()
            ->whereKey($contextId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get a default service context ID for situations where no client is specified.
     *
     * @return int|null
     */
    public function getDefault(): ?int
    {
        $defaultId = ServiceContext::defaultId();
        
        if ($defaultId && $this->isValidContext($defaultId)) {
            return $defaultId;
        }

        return ServiceContext::query()
            ->where('is_active', true)
            ->value('id');
    }
}

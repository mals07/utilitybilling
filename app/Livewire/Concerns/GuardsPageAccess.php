<?php

namespace App\Livewire\Concerns;

use App\Enums\Access;
use App\Enums\Page;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * For pages whose access is controlled by roles. Livewire actions are public endpoints, so every
 * action that changes data checks the user's access first.
 */
trait GuardsPageAccess
{
    /**
     * The page this component belongs to.
     */
    abstract protected function page(): Page;

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * The company the user works in.
     */
    protected function company(): Company
    {
        $company = $this->user()->company;

        abort_if($company === null, 403, 'Set up your company profile first.');

        return $company;
    }

    /**
     * Whether the user may do this; if not, says so in the flash message.
     */
    protected function allowed(Access $access): bool
    {
        if ($this->user()->canAccessPage($this->page(), $access)) {
            return true;
        }

        $this->flash($access->deniedMessage(), 'error');

        return false;
    }

    /**
     * What the user may do on this page, keyed by Access value.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function access(): array
    {
        return $this->user()->pageAccess($this->page());
    }

    /**
     * Shows a status message above the page that hides itself after a few seconds.
     */
    protected function flash(?string $message, string $tone = 'success'): void
    {
        if ($message !== null && $message !== '') {
            $this->dispatch('flash', message: $message, tone: $tone);
        }
    }

    /**
     * Paginates, clamping the page number to the pages that exist.
     *
     * @param  Builder<*>|Relation<*, *, *>  $query
     */
    protected function paginateClamped(Builder|Relation $query, int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        $total = (clone $query)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $page = min(max((int) $this->getPage($pageName), 1), $lastPage);

        return $query->paginate($perPage, ['*'], $pageName, $page);
    }
}

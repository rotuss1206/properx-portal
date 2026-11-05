<?php

namespace App\Auth;

use App\Member\MemberMenu;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where people go after the password and after the two-factor code. Nova's own responses follow any
 * "intended" address, which is whatever was opened while signed out, also someone else's page: a
 * staff dashboard for a member, or /member while MEMBER_AREA_ENABLED is off, both a 404. Here an
 * intended address counts only if this person can use it; otherwise they go home.
 */
class SignInResponse implements LoginResponse, TwoFactorLoginResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $intended = $request->session()->pull('url.intended');
        $user = $request->user();

        $target = is_string($intended) && $this->canOpen($user, $intended, $request)
            ? $intended
            : $this->home($user, $request);

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => $target], Response::HTTP_OK)
            : redirect($target);
    }

    private function home(User $user, Request $request): string
    {
        return $this->usesMemberArea($user) ? route('member.dashboard') : Nova::initialPathUrl($request);
    }

    private function usesMemberArea(User $user): bool
    {
        return config('portal.member_area.enabled') && $user->isMember() && ! $user->isStaff();
    }

    /**
     * A Nova dashboard that is not theirs is left to RedirectUnavailableNovaDashboard, which also
     * covers people who are already signed in.
     */
    private function canOpen(User $user, string $url, Request $request): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $novaPath = trim(Nova::path(), '/');

        // Nova's API answers scripts and data, never a page to land on.
        if ($this->isUnder($path, 'nova-api')) {
            return false;
        }

        if ($this->isUnder($path, 'member')) {
            return $this->usesMemberArea($user) && $this->memberSectionIsOpen($user, $path);
        }

        if (! $this->isUnder($path, $novaPath)) {
            return true;
        }

        return ! $this->usesMemberArea($user) && $this->novaResourceIsOpen($path, $novaPath, $request);
    }

    /**
     * A resource that does not exist is a 404 and one they may not list a 403. Single records are
     * left to Nova's policies: answering for them here would mean loading each one.
     */
    private function novaResourceIsOpen(string $path, string $novaPath, Request $request): bool
    {
        if (! $this->isUnder($path, $novaPath.'/resources')) {
            return true;
        }

        $key = explode('/', substr($path, strlen($novaPath) + 1))[1] ?? '';
        $resource = Nova::resourceForKey($key);

        return $resource !== null && $resource::authorizedToViewAny(NovaRequest::createFrom($request));
    }

    /**
     * Sections closed to this person answer 404 (agency people, a restricted membership).
     */
    private function memberSectionIsOpen(User $user, string $path): bool
    {
        $section = explode('/', $path)[1] ?? 'dashboard';

        return ! array_key_exists($section, MemberMenu::PAGES) || MemberMenu::visible($user, $section);
    }

    private function isUnder(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}

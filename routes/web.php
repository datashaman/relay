<?php

use App\Http\Controllers\OauthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/oauth/redirect/{provider}', [OauthController::class, 'redirect'])
    ->name('oauth.redirect')
    ->whereIn('provider', ['github', 'jira']);

Route::get('/oauth/callback/{provider}', [OauthController::class, 'callback'])
    ->name('oauth.callback')
    ->whereIn('provider', ['github', 'jira']);

Route::delete('/oauth/disconnect/{provider}', [OauthController::class, 'disconnect'])
    ->name('oauth.disconnect')
    ->whereIn('provider', ['github', 'jira']);

Route::get('/jira/sites', [OauthController::class, 'jiraSites'])
    ->name('jira.sites');

Route::post('/jira/select-site', [OauthController::class, 'jiraSelectSite'])
    ->name('jira.select-site');

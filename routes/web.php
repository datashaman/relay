<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\EscalationRuleController;
use App\Http\Controllers\FilterRuleController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueViewController;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\PreflightController;
use App\Http\Controllers\RunProgressController;
use App\Http\Controllers\RunTimelineController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::overview')->name('overview');
Route::livewire('/intake', 'pages::intake')->name('intake.index');
Route::get('/intake/sources/{source}/rules', [FilterRuleController::class, 'edit'])->name('intake.rules.edit');
Route::put('/intake/sources/{source}/rules', [FilterRuleController::class, 'update'])->name('intake.rules.update');

Route::post('/sources/{source}/test', [SourceController::class, 'testConnection'])->name('sources.test');
Route::post('/sources/{source}/sync', [SourceController::class, 'syncNow'])->name('sources.sync');

Route::post('/issues/stages/{stage}/approve', [IssueViewController::class, 'approve'])->name('issues.approve');
Route::post('/issues/stages/{stage}/reject', [IssueViewController::class, 'reject'])->name('issues.reject-stage');
Route::post('/issues/runs/{run}/guidance', [IssueViewController::class, 'guidance'])->name('issues.guidance');

Route::get('/issues/{issue}', [IssueViewController::class, 'show'])->name('issues.show');
Route::post('/issues/{issue}/accept', [IssueController::class, 'accept'])->name('issues.accept');
Route::post('/issues/{issue}/reject', [IssueController::class, 'reject'])->name('issues.reject');
Route::post('/sources/{source}/toggle-pause', [IssueController::class, 'togglePause'])->name('issues.toggle-pause');

Route::get('/runs/{run}/preflight', [PreflightController::class, 'show'])->name('preflight.show');
Route::post('/runs/{run}/preflight/answers', [PreflightController::class, 'submitAnswers'])->name('preflight.submit-answers');
Route::post('/runs/{run}/preflight/skip', [PreflightController::class, 'skipToDoc'])->name('preflight.skip');
Route::get('/runs/{run}/preflight/doc', [PreflightController::class, 'showDoc'])->name('preflight.doc');
Route::get('/runs/{run}/preflight/doc/edit', [PreflightController::class, 'editDoc'])->name('preflight.doc.edit');
Route::put('/runs/{run}/preflight/doc', [PreflightController::class, 'updateDoc'])->name('preflight.doc.update');

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

Route::get('/jira/select-site', [OauthController::class, 'jiraSiteSelectionForm'])
    ->name('jira.select-site.form');

Route::post('/jira/select-site', [OauthController::class, 'jiraSelectSite'])
    ->name('jira.select-site');

Route::get('/config', [ConfigController::class, 'index'])->name('config.index');
Route::post('/config/global', [ConfigController::class, 'updateGlobal'])->name('config.update-global');
Route::post('/config/stage/{stage}', [ConfigController::class, 'updateStage'])->name('config.update-stage');
Route::post('/config/iteration-cap', [ConfigController::class, 'updateIterationCap'])->name('config.update-iteration-cap');
Route::post('/escalation-rules', [EscalationRuleController::class, 'store'])->name('escalation-rules.store');
Route::put('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'update'])->name('escalation-rules.update');
Route::delete('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'destroy'])->name('escalation-rules.destroy');
Route::post('/escalation-rules/{escalationRule}/toggle', [EscalationRuleController::class, 'toggleEnabled'])->name('escalation-rules.toggle');
Route::post('/escalation-rules/{escalationRule}/move-up', [EscalationRuleController::class, 'moveUp'])->name('escalation-rules.move-up');
Route::post('/escalation-rules/{escalationRule}/move-down', [EscalationRuleController::class, 'moveDown'])->name('escalation-rules.move-down');

Route::get('/runs/{run}/progress', [RunProgressController::class, 'show'])->name('runs.progress');
Route::get('/runs/{run}/timeline', [RunTimelineController::class, 'show'])->name('runs.timeline');

Route::livewire('/activity', 'pages::activity')->name('activity.index');

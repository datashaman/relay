<?php

use App\Http\Controllers\EscalationRuleController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueViewController;
use App\Http\Controllers\JiraWebhookController;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\RunProgressController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::overview')->name('overview');
Route::livewire('/intake', 'pages::intake')->name('intake.index');
Route::livewire('/intake/sources/{source}/rules', 'pages::intake-rules')->name('intake.rules.edit');

Route::post('/sources/{source}/test', [SourceController::class, 'testConnection'])->name('sources.test');
Route::post('/sources/{source}/sync', [SourceController::class, 'syncNow'])->name('sources.sync');

Route::post('/issues/stages/{stage}/approve', [IssueViewController::class, 'approve'])->name('issues.approve');
Route::post('/issues/stages/{stage}/reject', [IssueViewController::class, 'reject'])->name('issues.reject-stage');
Route::post('/issues/stages/{stage}/retry', [IssueViewController::class, 'retry'])->name('issues.retry-stage');
Route::post('/issues/runs/{run}/guidance', [IssueViewController::class, 'guidance'])->name('issues.guidance');
Route::post('/issues/runs/{run}/resolve-conflicts', [IssueViewController::class, 'resolveConflicts'])->name('issues.resolve-conflicts');

Route::livewire('/issues/{issue}', 'pages::issue')->name('issues.show');
Route::post('/issues/{issue}/accept', [IssueController::class, 'accept'])->name('issues.accept');
Route::post('/issues/{issue}/reject', [IssueController::class, 'reject'])->name('issues.reject');
Route::post('/sources/{source}/toggle-pause', [IssueController::class, 'togglePause'])->name('issues.toggle-pause');

Route::livewire('/runs/{run}/preflight', 'pages::preflight-clarification')->name('preflight.show');
Route::livewire('/runs/{run}/preflight/doc', 'pages::preflight-doc')->name('preflight.doc');
Route::livewire('/runs/{run}/preflight/doc/edit', 'pages::preflight-edit-doc')->name('preflight.doc.edit');

Route::get('/oauth/{provider}/redirect', [OauthController::class, 'redirect'])
    ->name('oauth.redirect')
    ->whereIn('provider', ['github', 'jira']);

Route::get('/oauth/{provider}/callback', [OauthController::class, 'callback'])
    ->name('oauth.callback')
    ->whereIn('provider', ['github', 'jira']);

Route::delete('/oauth/{provider}/disconnect', [OauthController::class, 'disconnect'])
    ->name('oauth.disconnect')
    ->whereIn('provider', ['github', 'jira']);

Route::livewire('/jira/select-site', 'pages::jira-select-site')
    ->name('jira.select-site.form');

Route::livewire('/sources/{source}/repositories', 'pages::github-select-repos')
    ->name('github.select-repos');

Route::livewire('/sources/{source}/projects', 'pages::jira-select-projects')
    ->name('jira.select-projects');

Route::livewire('/sources/{source}/components', 'pages::components')
    ->name('components.index');

Route::livewire('/config', 'pages::config')->name('config.index');
Route::post('/escalation-rules', [EscalationRuleController::class, 'store'])->name('escalation-rules.store');
Route::put('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'update'])->name('escalation-rules.update');
Route::delete('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'destroy'])->name('escalation-rules.destroy');
Route::post('/escalation-rules/{escalationRule}/toggle', [EscalationRuleController::class, 'toggleEnabled'])->name('escalation-rules.toggle');
Route::post('/escalation-rules/{escalationRule}/move-up', [EscalationRuleController::class, 'moveUp'])->name('escalation-rules.move-up');
Route::post('/escalation-rules/{escalationRule}/move-down', [EscalationRuleController::class, 'moveDown'])->name('escalation-rules.move-down');

Route::get('/runs/{run}/progress', [RunProgressController::class, 'show'])->name('runs.progress');
Route::livewire('/runs/{run}/timeline', 'pages::run-timeline')->name('runs.timeline');

Route::livewire('/activity', 'pages::activity')->name('activity.index');

Route::post('/webhooks/github/{source}', GitHubWebhookController::class)->name('webhooks.github');
Route::post('/webhooks/jira/{source}/{token}', JiraWebhookController::class)->name('webhooks.jira');

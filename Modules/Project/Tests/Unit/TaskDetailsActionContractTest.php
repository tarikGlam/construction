<?php

namespace Modules\Project\Tests\Unit;

use PHPUnit\Framework\TestCase;

class TaskDetailsActionContractTest extends TestCase
{
    public function test_every_task_mutation_form_has_its_own_post_action_and_csrf_token(): void
    {
        $view = file_get_contents(__DIR__ . '/../../Resources/views/backend/task/details.blade.php');

        foreach ([
            'assigned_form' => "route('tasks.assigned', \$task)",
            'discussions_form' => "route('task_discussions.store', \$task)",
            'progress_form' => "route('task_progress.store', \$task)",
            'files_form' => "route('task_files.store', \$task)",
            'note_form' => "route('task_notes.store', \$task)",
        ] as $formId => $route) {
            $this->assertMatchesRegularExpression(
                '/<form[^>]+method="post"[^>]+action="\{\{ ' . preg_quote($route, '/') . ' \}\}"[^>]+id="' . $formId . '"/s',
                $view,
                "{$formId} must fall back to its mutation route when JavaScript is unavailable."
            );
        }

        $this->assertSame(5, substr_count($view, '@csrf'));
        $this->assertSame(5, substr_count($view, 'url: this.action'));
        $this->assertStringNotContainsString('bug_status_form', $view);
    }

    public function test_task_deletes_use_named_delete_routes_with_csrf(): void
    {
        $view = file_get_contents(__DIR__ . '/../../Resources/views/backend/task/details.blade.php');
        $routes = file_get_contents(__DIR__ . '/../../Routes/web.php');

        $this->assertStringContainsString("route('task_discussions.destroy', ':id')", $view);
        $this->assertStringContainsString("route('task_files.destroy', ':id')", $view);
        $this->assertGreaterThanOrEqual(2, substr_count($view, "method: 'DELETE'"));
        $this->assertGreaterThanOrEqual(2, substr_count($view, 'data: {_token: "{{ csrf_token() }}"}'));
        $this->assertStringContainsString("Route::delete('tasks/{id}/discussions'", $routes);
        $this->assertStringContainsString("Route::delete('tasks/{id}/files'", $routes);
        $this->assertStringNotContainsString("Route::get('tasks/{id}/delete_discussions'", $routes);
        $this->assertStringNotContainsString("Route::get('tasks/{id}/delete_files'", $routes);
    }
}

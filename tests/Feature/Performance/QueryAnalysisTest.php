<?php

namespace Tests\Feature\Performance;

use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueryAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // Load test data
    }

    /** @test */
    public function it_analyzes_feedback_listing_query()
    {
        // Login as a user to trigger tenant scope
        $user = User::first();
        $this->actingAs($user);

        // Get a project
        $project = Project::first();

        if (!$project) {
            $this->markTestSkipped('No projects available for testing');
        }

        // Clear any previous queries
        DB::flushQueryLog();

        // Enable query log
        DB::enableQueryLog();

        // Run the query we want to analyze
        $feedback = Feedback::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get the actual SQL
        $queries = DB::getQueryLog();

        if (empty($queries)) {
            $this->markTestSkipped('No queries were logged');
        }

        $query = $queries[0];
        $sql = $query['query'] ?? $query['sql'];
        $bindings = $query['bindings'];

        // Run EXPLAIN
        $explain = DB::select("EXPLAIN " . $sql, $bindings);

        // Output results for analysis
        echo "\n=== FEEDBACK LISTING QUERY ANALYSIS ===\n";
        echo "Query: " . $sql . "\n";
        echo "Bindings: " . json_encode($bindings) . "\n\n";
        echo "EXPLAIN Output:\n";
        foreach ($explain as $row) {
            print_r((array)$row);
        }

        // Assert query is using an index
        $usingIndex = collect($explain)->some(function ($row) {
            return !empty($row->key);
        });

        $this->assertTrue($usingIndex, "Query should use an index!");
    }

    /** @test */
    public function it_analyzes_project_with_relationships_query()
    {
        $user = User::first();

        if (!$user) {
            $this->markTestSkipped('No users available for testing');
        }

        $this->actingAs($user);

        $project = Project::first();

        if (!$project) {
            $this->markTestSkipped('No projects available for testing');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Load project with relationships and counts
        $projectWithRelations = Project::with(['tenant', 'division'])
            ->withCount('feedbacks')
            ->find($project->id);

        $queries = DB::getQueryLog();

        echo "\n=== PROJECT WITH RELATIONSHIPS QUERY ANALYSIS ===\n";
        echo "Number of queries executed: " . count($queries) . "\n\n";

        foreach ($queries as $index => $query) {
            $sql = $query['query'] ?? $query['sql'];
            $bindings = $query['bindings'];

            echo "Query #" . ($index + 1) . ":\n";
            echo $sql . "\n";
            echo "Bindings: " . json_encode($bindings) . "\n";
            echo "Time: " . ($query['time'] ?? 0) . "ms\n\n";

            // Run EXPLAIN on SELECT queries only
            if (stripos($sql, 'SELECT') === 0) {
                $explain = DB::select("EXPLAIN " . $sql, $bindings);
                echo "EXPLAIN:\n";
                foreach ($explain as $row) {
                    print_r((array)$row);
                }
            }
            echo "\n---\n\n";
        }

        // Assert we're not running too many queries (N+1 problem check)
        $this->assertLessThanOrEqual(5, count($queries), "Should not have N+1 query problem");
    }

    /** @test */
    public function it_analyzes_user_divisions_query()
    {
        $user = User::first();

        if (!$user) {
            $this->markTestSkipped('No users available for testing');
        }

        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Get user's divisions with pivot data
        $divisions = $user->divisions()->get();

        $queries = DB::getQueryLog();

        if (empty($queries)) {
            $this->markTestSkipped('No queries were logged');
        }

        $query = $queries[0];
        $sql = $query['query'] ?? $query['sql'];
        $bindings = $query['bindings'];

        echo "\n=== USER DIVISIONS QUERY ANALYSIS ===\n";
        echo "Query: " . $sql . "\n";
        echo "Bindings: " . json_encode($bindings) . "\n\n";

        $explain = DB::select("EXPLAIN " . $sql, $bindings);
        echo "EXPLAIN Output:\n";
        foreach ($explain as $row) {
            print_r((array)$row);
        }

        $usingIndex = collect($explain)->some(function ($row) {
            return !empty($row->key);
        });

        $this->assertTrue($usingIndex, "Query should use an index!");
    }
}

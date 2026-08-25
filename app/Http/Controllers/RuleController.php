<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexRuleRequest;
use App\Http\Requests\StoreRuleRequest;
use App\Http\Requests\UpdateRuleRequest;
use App\Http\Resources\RuleResource;
use App\Jobs\ProcessAllRules;
use App\Models\Rule;
use App\Support\LiteralContains;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RuleController extends Controller
{
    public function index(IndexRuleRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Rule::query()
            ->with(['account', 'category.parent'])
            ->when(isset($validated['account_id']), fn ($query) => $query->where('account_id', $validated['account_id']))
            ->when(isset($validated['category_id']), fn ($query) => $query->where('category_id', $validated['category_id']))
            ->orderByRaw('LOWER(content)')
            ->orderBy('id');

        if (isset($validated['search'])) {
            LiteralContains::apply($query, 'content', $validated['search']);
        }

        return RuleResource::collection(
            $query->paginate($validated['per_page'] ?? 25)->withQueryString()
        );
    }

    public function store(StoreRuleRequest $request): RuleResource
    {
        $validated = $request->validated();
        $rule = Rule::query()->create([
            'content' => $validated['match_text'],
            'account_id' => $validated['account_id'] ?? null,
            'category_id' => $validated['category_id'],
        ]);

        ProcessAllRules::dispatch();

        return new RuleResource($rule->load(['account', 'category.parent']));
    }

    public function show(Rule $rule): RuleResource
    {
        $rule->load(['account', 'category.parent']);

        return new RuleResource($rule);
    }

    public function update(UpdateRuleRequest $request, Rule $rule): RuleResource
    {
        $validated = $request->validated();
        $changes = [];
        if (array_key_exists('match_text', $validated)) {
            $changes['content'] = $validated['match_text'];
        }
        if (array_key_exists('account_id', $validated)) {
            $changes['account_id'] = $validated['account_id'];
        }
        if (array_key_exists('category_id', $validated)) {
            $changes['category_id'] = $validated['category_id'];
        }
        $rule->update($changes);

        ProcessAllRules::dispatch();

        return new RuleResource($rule->refresh()->load(['account', 'category.parent']));
    }

    public function destroy(Rule $rule): Response
    {
        $rule->delete();

        return response()->noContent();
    }
}

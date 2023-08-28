<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleRequest;
use App\Http\Requests\UpdateRuleRequest;
use App\Http\Resources\RuleResource;
use App\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RuleResource::collection(
            Rule::filters($request->get("filter"))
                ->orders($request->get("order"))
                ->with(["account", "category"])
                ->paginate()
        );
    }

    public function store(StoreRuleRequest $request): RuleResource
    {
        $validated = $request->validated();

        $validated["account_number"] = $validated["account"]["account_number"];
        unset($validated["account"]);

        $validated["category_id"] = $validated["category"]["id"];
        unset($validated["category"]);

        $rule = Rule::create($validated);
        return new RuleResource($rule);
    }

    public function show(Rule $rule): RuleResource
    {
        $rule->load(["account", "category"]);
        return new RuleResource($rule);
    }

    public function update(UpdateRuleRequest $request, Rule $rule)
    {
        $validated = $request->validated();

        $validated["account_number"] = $validated["account"]["account_number"];
        unset($validated["account"]);

        $validated["category_id"] = $validated["category"]["id"];
        unset($validated["category"]);

        $rule->update($validated);
        return new RuleResource($rule->refresh());
    }

    public function destroy(Rule $rule): Response
    {
        $rule->delete();
        return response()->noContent();
    }
}

<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $currentTenant = Tenant::current();
        if ($currentTenant) {
            $this->seedTenant($currentTenant);

            return;
        }

        try {
            Tenant::query()
                ->whereIn('slug', ['personal', 'clinic'])
                ->each(fn (Tenant $tenant) => $tenant->execute(
                    fn () => $this->seedTenant($tenant)
                ));
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seedTenant(Tenant $tenant): void
    {
        foreach ($this->plan($tenant->slug) as $groupName => [$type, $children]) {
            $group = Category::query()->firstOrCreate(
                ['parent_id' => null, 'name' => $groupName],
                ['type' => $type]
            );

            foreach ($children as $childName) {
                Category::query()->firstOrCreate(
                    ['parent_id' => $group->id, 'name' => $childName],
                    ['type' => $type]
                );
            }
        }
    }

    private function plan(string $tenantSlug): array
    {
        return match ($tenantSlug) {
            'clinic' => $this->clinicPlan(),
            'personal' => $this->personalPlan(),
            default => [],
        };
    }

    private function clinicPlan(): array
    {
        return [
            'Revenue' => ['income', ['OHIP', 'Uninsured services', 'Private insurance', 'Third-party reports and forms', 'Procedures', 'Other revenue']],
            'Direct clinical costs' => ['expense', ['Medical supplies', 'Medications', 'Laboratory services', 'Contract clinical staff', 'Equipment maintenance']],
            'Staffing' => ['expense', ['Salaries and wages', 'Exam-based compensation', 'Employer payroll costs', 'Benefits', 'Contractors']],
            'Occupancy' => ['expense', ['Rent', 'Utilities', 'Cleaning', 'Repairs and maintenance', 'Security']],
            'Administration' => ['expense', ['Software and subscriptions', 'Office supplies', 'Professional fees', 'Licences and permits', 'Insurance', 'Marketing', 'Education and training', 'Bank and merchant fees']],
            'Financing costs' => ['expense', ['Loan interest']],
            'Taxes and reserves' => ['expense', ['Estimated income tax', 'Payroll remittances', 'HST payments', 'Other taxes']],
            'Capital expenditures' => ['expense', ['Medical equipment', 'Computers', 'Furniture', 'Leasehold improvements']],
            'Debt principal and transfers' => ['transfer', ['Equipment loan principal', 'Other loan principal', 'Transfers between accounts']],
            'Owner transactions' => ['transfer', ['Owner contributions', 'Owner withdrawals']],
        ];
    }

    private function personalPlan(): array
    {
        return [
            'Income' => ['income', ['Salary', 'Clinic distributions', 'Investment income', 'Other income']],
            'Housing' => ['expense', ['Mortgage or rent', 'Property tax', 'Utilities', 'Maintenance and repairs', 'Home insurance']],
            'Food' => ['expense', ['Groceries', 'Dining out']],
            'Transportation' => ['expense', ['Vehicle payments', 'Fuel', 'Vehicle insurance', 'Maintenance', 'Public transit']],
            'Health' => ['expense', ['Medical and dental', 'Prescriptions', 'Health insurance']],
            'Personal and lifestyle' => ['expense', ['Shopping', 'Entertainment', 'Travel', 'Education']],
            'Taxes and financial costs' => ['expense', ['Income tax', 'Bank fees', 'Interest expense']],
            'Savings and investments' => ['transfer', ['Investment contributions', 'Investment withdrawals']],
            'Debt principal and transfers' => ['transfer', ['Debt principal', 'Transfers between accounts']],
        ];
    }
}

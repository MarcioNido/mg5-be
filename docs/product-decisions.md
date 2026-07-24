# MG5 Product Decisions

## Product purpose

Money Guru 5 (MG5) is a small management-finance application for two distinct
financial contexts:

- Personal finances.
- A small ophthalmology clinic in Toronto, Ontario, Canada.

The clinic uses an external accountant for bookkeeping, tax filings, and formal
financial statements. MG5 does not replace that work. Its purpose is to provide
a practical and trustworthy view of cash, operating performance, debt, and the
capacity to invest or expand.

## Primary questions

MG5 should make it easy to answer:

- How much revenue and operating cash did the clinic generate this month?
- Which costs are increasing?
- How much cash remains after operating costs, taxes set aside, debt payments,
  and equipment purchases?
- Are all bank and credit-card transactions represented in MG5?
- Can the clinic safely hire, buy equipment, or expand?

## Product principles

1. Keep routine use simple. CSV import, automatic categorization, and bank
   balance confirmation are the core workflow.
2. Bank data is the source of truth for cash.
3. Manual entries complement imported data and must not create duplicates.
4. Management reporting is more important than tax-accounting completeness.
5. Tax estimates and accountant mappings may be recorded, but MG5 does not
   calculate or file taxes.
6. Prefer a small, explicit feature set over a configurable accounting system.

## Language and locale

- Product and account names for the clinic are in English.
- Default currency is CAD.
- Default timezone is `America/Toronto`.
- Personal and clinic data remain isolated even when the same user can access
  both.

## Initial product areas

- Dashboard.
- Transactions.
- CSV imports.
- Categories and categorization rules.
- Bank accounts and credit cards.
- Balance reconciliation.
- Small set of optional management adjustments.

Features such as formal double-entry accounting, tax filing, payroll processing,
inventory accounting, and a general-purpose ERP are explicitly out of scope.

## Management reporting model

The initial reports should separate:

- Revenue.
- Direct clinical costs.
- Staffing costs.
- Occupancy costs.
- Administrative costs.
- Interest expense.
- Estimated tax reserve.
- Loan principal payments.
- Capital expenditures.
- Owner contributions, withdrawals, and transfers.

This separation allows MG5 to show operating result and cash flow without
mistaking loan principal, account transfers, or equipment purchases for normal
operating expenses.

## Initial clinic category groups

- Revenue: OHIP, uninsured services, private insurance, third-party reports and
  forms, procedures, and other revenue.
- Direct clinical costs: medical supplies, medications, laboratory services,
  contract clinical staff, and equipment maintenance.
- Staffing: regular salaries, exam-based compensation, employer payroll costs,
  benefits, and contractors.
- Occupancy: rent, utilities, cleaning, repairs, and security.
- Administration: software, office supplies, professional fees, licences,
  insurance, marketing, education, bank charges, and merchant fees.
- Financing: loan interest and loan principal.
- Taxes and reserves: estimated income tax, payroll remittances, HST payments,
  and other tax payments.
- Capital expenditures: medical equipment, computers, furniture, and leasehold
  improvements.
- Owner transactions and internal transfers.

The external accountant can later provide optional account codes or mappings
without changing MG5's management-oriented category structure.

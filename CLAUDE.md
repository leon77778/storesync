# StoreSync — Claude Code Context

## Project Overview
StoreSync is a Laravel eCommerce order import pipeline built as part of a
technical interview assignment for magic42, a Birmingham-based eCommerce agency.

## My Stack
- PHP 8.2 / Laravel 11
- MySQL (database)
- Redis (queue driver)
- Laravel Horizon (queue dashboard)
- Tailwind CSS (frontend)
- Mailtrap (email testing)

## Architecture Goals
- CSV upload triggers queued background jobs
- Each order row = one dispatched job
- Jobs validate data, calculate totals, send confirmation email
- Dashboard shows real-time job status (pending/processing/completed/failed)

## Key Constraints
- Follow Laravel conventions strictly (MVC, service classes, form requests)
- Every job must be retryable on failure
- Code must be clean, well-commented, and testable
- Prefer explicit architecture decisions over magic/shortcuts

## Agentic Workflow Notes
- I am the decision-maker — propose options, don't just implement
- Explain trade-offs before writing code
- Flag when a solution has security implications
- This project runs on Windows (XAMPP) locally, targets Linux in production

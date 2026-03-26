# Contributing to HAWKI

Thank you for your interest in contributing to HAWKI! We appreciate every contribution — whether it's reporting a bug, fixing code, enhancing documentation, or suggesting new features. This guide will help you make contributions that are easy to review and merge.

We want HAWKI to be an open and welcoming project for everyone — regardless of background or experience. All participants are expected to treat others with respect and courtesy.

---

## Table of Contents

1. [How to Contribute](#how-to-contribute)
2. [Development Workflow](#development-workflow)
3. [Pull Request Process](#pull-request-process)
4. [Code Review Process](#code-review-process)
5. [Getting Help](#getting-help)

---

## How to Contribute

### Types of Contributions

We welcome various types of contributions:

#### Reporting Bugs

- Search existing issues first to avoid duplicates
- Use a clear, descriptive title
- Describe the exact steps to reproduce the problem
- Include details about your environment (OS, PHP version, etc.)
- Add screenshots or error messages if applicable

#### Suggesting Features

- Check if the feature has already been suggested
- Clearly describe the feature and its use case
- Explain why this feature would benefit other users
- Be open to discussion and alternative approaches

#### Fixing Bugs

- Reference the issue number in your commit and PR
- Add tests that verify the bug is fixed
- Ensure you don't break existing functionality

#### Adding Features

- Discuss the feature in an issue before implementing
- Follow the project's [architecture](./CodeStructure.md) and [coding standards](./CodeStyle.md)
- Include tests and documentation
- Keep the feature focused and avoid scope creep

#### Improving Documentation

- Fix typos, clarify explanations, add examples
- Keep documentation in sync with code changes
- Follow the existing documentation style

---

## Development Workflow

### Branching Strategy

We use the following branching model:

- **`development`** — **Default branch** for integration of new features and fixes (always branch from here)
- **`main`** — Stable release branch (production-ready code)
- **`feature/*`** — New functionality (e.g., `feature/user-notifications`)
- **`bugfix/*`** — Fixes for issues (e.g., `bugfix/login-validation`)
- **`hotfix/*`** — Urgent production fixes (e.g., `hotfix/security-patch`)

#### Creating a Branch

**Always branch from `development`** unless it's a critical fix for production:

```bash
# Update your local development branch
git checkout development
git pull upstream development

# Create a new feature branch
git checkout -b feature/your-feature-name
```

### Commit Message Guidelines

We follow **[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/)** for consistent and readable commit history.

#### Format

```
type(scope): Short summary in present tense

Optional longer description explaining what and why,
not how. Wrap at 72 characters.

Refs #123
```

#### Common Types

- **feat**: A new feature
- **fix**: A bug fix
- **docs**: Documentation changes
- **style**: Code style changes (formatting, missing semicolons, etc.)
- **refactor**: Code changes that neither fix bugs nor add features
- **test**: Adding or updating tests
- **chore**: Changes to build process, dependencies, or tooling

For the full specification, see: [conventionalcommits.org](https://www.conventionalcommits.org/en/v1.0.0/)

#### Examples

```bash
feat(auth): add two-factor authentication

Implements TOTP-based 2FA for user accounts.
Users can enable 2FA in their profile settings.

Refs #145

fix(profile): validate email format before save

Prevents invalid email addresses from being stored.
Adds server-side validation to complement client-side checks.

Refs #298

docs(readme): update installation instructions

Clarifies database setup steps and adds troubleshooting section.
```

#### Commit Message Rules

- Use present tense ("add feature" not "added feature")
- Use imperative mood ("move cursor to" not "moves cursor to")
- Keep the subject line under 50 characters
- Reference related issue numbers when relevant
- Write meaningful commit messages that explain **why**, not just **what**

### Keeping Your Branch Updated

Regularly sync your branch with the upstream repository:

```bash
# Fetch latest changes from upstream
git fetch upstream

# Rebase your feature branch onto the latest development
git checkout feature/your-feature-name
git rebase upstream/development

# Force push to your fork (only for your own branches!)
git push origin feature/your-feature-name --force-with-lease
```

---

## Pull Request Process

### Before Creating a PR

1. **Ensure your branch is up to date** with `development`:
   ```bash
   git fetch upstream
   git rebase upstream/development
   ```

2. **Review your own changes** before submitting

3. **Check that your code follows our standards:**
   - [Code Structure & Architecture](./CodeStructure.md)
   - [Code Style & Standards](./CodeStyle.md)

### PR Scope & Size

**Keep PRs small and focused:**
- A PR should not contain hundreds of changed files
- Large refactors must be split into logical steps across multiple PRs
- One PR = one responsibility

**One feature, bugfix, or refactor** (or a small, tightly related set):
- Avoid mixing refactors, formatting, and feature changes
- If a change requires touching many files, explain why in the PR description

#### Examples

**Bad:**
- Feature + unrelated refactor + formatting changes
- "Cleanup" PRs touching half the codebase
- Multiple unrelated bug fixes in one PR

**Good:**
- One feature with its services, tests, and wiring
- One refactor improving a specific subsystem
- One bug fix with its test

### PR Title

Use the same format as commit messages:

```
feat(auth): add two-factor authentication
fix(profile): validate email format
docs(contributing): update testing section
```

### PR Description

Please write a clear description explaining **WHAT** you did and **WHY** you did it.

**Good descriptions include:**
- Brief summary of the changes
- The problem being solved or feature being added
- Why this approach was chosen
- Any relevant context or trade-offs
- Related issue numbers (e.g., "Closes #123")

**Example:**

```markdown
## What

Adds two-factor authentication using TOTP (Time-based One-Time Password).

## Why

Users requested additional account security. 2FA significantly reduces the risk of unauthorized access even if passwords are compromised.

## How

- Implemented TOTP generation and verification
- Added UI for enabling/disabling 2FA in user settings
- Created FormRequests for validation
- Added API endpoints for QR code generation

## Related Issues

Closes #145
```

### Draft PRs

If you want early feedback or need architectural guidance:

**Open a Draft PR**:
1. Create a PR and mark it as "Draft"
2. Add `[WIP]` or `[Draft]` to the title
3. Request specific feedback in the description
4. Mark as "Ready for review" when complete

---

## Code Review Process

We aim for thoughtful, respectful reviews:

### For Contributors

- **Expect reviews before merge** — All PRs require at least one approval
- **Address suggestions** — Respond to feedback and make requested changes
- **Resolve conversations** — Mark conversations as resolved once addressed
- **Ask questions** — If feedback is unclear, ask for clarification
- **Be patient** — Reviews may take time depending on complexity

### For Reviewers

- **Be respectful and constructive** — Critique code, not people
- **Explain the "why"** — Don't just say what's wrong, explain why
- **Suggest alternatives** — Provide examples or better approaches
- **Approve small improvements** — Don't block on minor style preferences
- **Use conventional comments**:
  - **Blocking:** "This will cause a bug because..."
  - **Non-blocking:** "Consider using X here for better readability"
  - **Question:** "Why did you choose this approach?"
  - **Praise:** "Nice solution! This is much cleaner."

---

## Getting Help

### Where to Get Help

- **[GitHub Issues](https://github.com/YOUR_ORG/hawki/issues)** — Report bugs or request features
- **[GitHub Discussions](https://github.com/YOUR_ORG/hawki/discussions)** — Ask questions, propose ideas, get help
- **[Discord](https://discord.gg/your-invite-link)** — Real-time chat with the community and support in our **SOS Support** channel
- **[Documentation](https://docs.hawki.app)** — Check existing docs and guides

### Good First Issues

New to the project? Look for issues labeled:
- `good first issue` — Beginner-friendly tasks
- `help wanted` — Contributors welcome
- `documentation` — Documentation improvements

### Before Asking

1. **Search existing issues and discussions** — Your question may already be answered
2. **Check our [SOS Support channel](https://discord.gg/your-channel-link) on Discord** — Browse previous questions and solutions
3. **Check the documentation** — Look for guides and FAQs
4. **Review recent PRs** — See how others solved similar problems

### When in Doubt

If you're unsure about:
- Where code should live
- Whether something should be abstracted
- How much is "too much" abstraction
- The right architectural approach

**Open a Draft PR or discussion early.**

Architectural discussions are encouraged before code grows roots. We'd rather guide you early than request major rewrites later.

---

## Final Notes

### Philosophy

This project values:
- **Clarity over cleverness** — Simple, readable code wins
- **Consistency over personal preference** — Follow established patterns
- **Long-term maintainability** — Think about the next developer
- **Incremental improvement** — Small, focused changes compound

### Recognition

All contributors will be recognized in the project. We value every contribution, no matter how small.

### Questions?

If anything in this guide is unclear or you need help, don't hesitate to ask. We're here to help you succeed.

Thank you for contributing to HAWKI! 🧡

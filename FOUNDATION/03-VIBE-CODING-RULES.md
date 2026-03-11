# Vibe Coding Rules (So AI Helps, Not Hurts)

## Golden loop
1. Ask AI for plan
2. Ask AI for minimal implementation
3. Run/test yourself
4. Ask AI to fix only failing parts
5. Commit small

## Prompt skeleton
- Goal
- Stack
- Constraints
- Output format
- Done condition

## Hard rules
- Never paste secrets in prompts
- Never merge code you didn't run
- Ask for diff-style changes on existing code
- Keep tasks small (1 feature per prompt)

## Quality gate prompt
"Review this output for correctness, security, performance, and readability. Return blocking issues first."

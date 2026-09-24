# phox/browser-agent

Probably the fastest goal-driven browser agent for PHP. It drives a remote Chrome over CDP, picks
each action from the page's live controls, and returns evidence and page diagnostics.

The package knows nothing about Atarim. The Laravel application supplies configuration, the HTTP
client and the logger, and owns everything product-specific: the AI tool, the session-handle
registry and its sweep, credits and workspace scoping. Nothing here imports `App\`.

## Code

- PHP 8.3, strict types, PSR-12. `pint.json` at the package root is authoritative.
- Framework-agnostic: Guzzle and PSR interfaces (`Psr\Log\LoggerInterface`, PSR-18/PSR-17 where it
  fits). Laravel integration lives in one optional service provider, and nothing else depends on it.
- At most 3 return statements per method, cognitive complexity at most 15. Split long methods into
  private methods or dedicated classes.
- Constructor promotion, typed and readonly properties where they fit. Classes stay small and single-purpose.
- Ordering follows Pint's `ordered_class_elements`.
- Pest tests for every behaviour, happy path and key edge cases. Tests run offline: fake the CDP
  transport and the model HTTP calls. No test calls Browser Run, TypeSafe or any paid API.

## Engine invariants

- A browser mutation (typing, selecting, a click that submits) is never retried automatically. A
  retry can resubmit a form on a customer's live site.
- `read_only` removes typing, selecting and submitting controls from the action space. It covers the
  agent's own actions only; caller-supplied JavaScript runs whatever it says.
- Caller JavaScript is a caller input, run outside the decision loop. The decision model chooses from
  observed controls and never emits selectors or code.
- Every value returned to the caller has a size cap: evidence, faults, query results, reports.
- One HTTP client is reused for every model call in a run. A fresh TLS connection per request costs
  ~540ms against ~205ms on a reused one.
- Each run gets its own browser context, disposed at close, so cookies and storage never cross
  between sites.

## Writing

Comments, docblocks, README and commit messages follow these rules.

- A comment states what the code is now, never what it was or what broke. Git holds history.
- A comment carries a fact the code cannot: a wire or platform fact, a trap, an invariant, a why-not
  decision, the measurement behind a threshold. If the name and type already say it, delete it.
- Keep the number that justifies a decision, stated in the present: "a reconnect costs ~130ms" is why
  sessions are reused.
- Cite only what a reader can open. Write the fact instead of a pointer to a plan or decision id.
- Name things instead of counting them. A count or documented default that matters is pinned by a
  test that fails when code and prose disagree.
- One fact per sentence. No closing epigram, no section banners, no docblock restating a signature.
- No temporal markers ("now", "currently", "still"), no "actually", "simply", "genuinely". Use
  " - " for a dash, and "instead of" for "rather than".
- Code does things; it does not want, earn, buy or refuse on principle.
- Say it once: a reason shared by several call sites belongs on the thing they call.
- Prompt text and model-facing strings are behaviour, not prose. Change them as deliberate,
  separately tested work, never as part of a tidy-up.
- Commit messages: imperative subject describing the behaviour change; the body says what was
  broken and what the fix does. Report what was measured and what was not; never call work complete
  when a part was skipped or a test is red.

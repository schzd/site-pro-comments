---
description: Walk ONE comment from the public form to the admin list, proving each step live and naming the test that locks it
---
Perform the review walkthrough required by the homework, on the running stack.

For each step S1…S9 (see qa/steps.php):
- say in one sentence what happens at this step and in which file (`explain_step`);
- show live evidence where it exists:
  - S2/S3/S5: `post_comment` (env dev) → the 201 response, the comment id, `realtime.delivered`, `subscribers`, `trace`;
  - S6/S8: `watch_admin_socket` with probe → the admin frame with the same trace id and its latency;
- name the test(s) that lock the step, with their last result (`explain_step` / docs/TRACEABILITY.md).

Finish with: `run_tests` suite `smoke` (fast proof the deployed stack is healthy) and a summary table
`Step | What happens | Evidence | Locked by`.

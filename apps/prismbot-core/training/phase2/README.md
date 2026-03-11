# Omni Phase 2 training data

Place your source supervision rows here.

## raw.csv format

```csv
prompt,response
"How do I restart the service safely?","Run systemctl --user restart prismbot-core.service, then verify with systemctl --user is-active prismbot-core.service"
```

Then normalize:

```bash
python3 ../../scripts/omni_phase2_build_dataset.py --in raw.csv --out omni_sft.jsonl
```

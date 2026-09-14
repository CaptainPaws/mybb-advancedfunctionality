# Character ability effects extension

Individual abilities remain embedded in `character_abilities[]` of
`af_kb.character.contract.v1`. This is an optional, backward-compatible
extension; it is not an independent Ability contract or storage entity.

```json
{
  "cooldown_value": "2",
  "cooldown_unit": "own_turn",
  "cost_value": "20",
  "cost_resource": "energy",
  "effects": [
    {
      "effect_type": "damage",
      "value": "800",
      "formula_profile": "",
      "coefficient": "",
      "target": "",
      "damage_type": "",
      "element": "fire",
      "duration_value": "",
      "duration_unit": "",
      "status_key": "",
      "stat_key": "",
      "operation": "",
      "resource_key": "",
      "notes": ""
    }
  ]
}
```

- An empty effect `formula_profile` inherits the ability `formula_profile`.
- An empty effect `target` inherits the ability `targeting`/`target`.
- An empty effect `damage_type` inherits the ability `damage_type`.
- `duration_value` on an ability is its general duration; every effect has its
  own `duration_value` and `duration_unit`.
- `damage_value`, `heal_value`, and `shield_value` remain valid legacy
  fallbacks when no structured effects are present.
- Reading a legacy character does not synthesize effects or rewrite its JSON.

# Fixtures

Bodies of `GET /shipments`, used by `tests/Unit/Status/StatusClassifierTest.php` and `tests/Unit/DhlTrackingClientTest.php`.

- `spec-example-transit.json` is the example response from the API spec (`docs/dhl-tracking-unified.yaml`).
- The other files are **hand-made** in the shape of the spec. DHL publishes no list of event texts, so the wording is taken from DHL's public tracking pages and may differ from what the API really sends.

When a real response is classified wrongly:

1. Save the body as a new fixture here. `composer test:sandbox` writes every sandbox response to `var/sandbox/`; for production, log `TrackingStatus::$shipment->raw`. Remove names and street addresses first.
2. Add a case to `StatusClassifierTest::fixtures()` with the state you expected.
3. Add the missing phrase to `src/Status/Keywords.php`, or change the rule in `src/Status/StatusClassifier.php`.

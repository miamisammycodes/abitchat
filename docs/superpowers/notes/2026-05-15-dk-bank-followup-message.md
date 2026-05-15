# Message to DK Bank Integration Team

Copy-paste this when sending the follow-up:

---

**Subject:** UAT integration — three blockers from today's testing

Hi team,

We've made good progress integrating the QR Payment Gateway API. Three blockers came up during UAT testing today that need your help:

### 1. UAT DK Bank mobile app build

We tried to scan a UAT-generated QR with the DK Bank mobile app on our test phone and got **`Invalid QR code. Please try again. <ERR: 2052>`**. This matches the warning in our integration guide that UAT QRs are only payable from the UAT DK Bank app build.

**Could you share the UAT DK app build?** (TestFlight invite for iOS, signed APK or Diawi link for Android, or whatever your standard distribution is.)

Without it we can't end-to-end test the DK→DK payment flow against UAT — we'd have to skip straight to a small real-money payment in production, which we'd prefer to avoid.

### 2. Cross-bank UAT payments — does BoB → UAT DK actually work end-to-end?

We tried to pay our UAT beneficiary account (`110158212197`) from a Bank of Bhutan mobile app by scanning the UAT-generated QR. The BoB app **scans and decodes the QR correctly** (great — confirms the RMA common standard works for cross-bank), and it shows the right reference number and amount. But when we tap **Submit**, the button doesn't respond — no error, no success, nothing happens.

Possible explanations:
- BoB blocks real-money transfers to UAT test beneficiaries (sanity check)
- BoB has a min-amount validation we're hitting at Nu. 1.00
- Some other BoB-side limitation

**Question:** is cross-bank payment routing to UAT DK beneficiaries actually supported end-to-end? If yes, do we need to coordinate with BoB's UAT side too? If no, do you have any way for us to simulate a cross-bank payment landing in our UAT beneficiary so we can test `/v1/intra-transaction/status` with a real bank-issued RRN?

This matters because your earlier reply confirmed that cross-bank verification works via `/v1/intra-transaction/status` if we obtain the RRN from the payer's bank. We'd like to empirically validate that before going live, not after.

### 3. `X-gravitee-api-key` — which key is current?

Page 4 of the spec lists two API keys:
- `98cf3639-df33-4587-9d36-dae9d2bb974c` — for `/v1/auth/token` and `/v1/sign/key`
- `595987da-fa2d-484f-82e3-b3a330d5c768` — for all other endpoints

In our UAT testing today, the **`98cf3639-...` key returns HTTP 401** on all endpoints. Only `595987da-...` works, and it works for ALL endpoints including token and sign/key fetch.

Has `98cf3639-...` been rotated out? Will production use one key or two? We'd like to confirm before we deploy.

### Status on our side

- API integration is built. All endpoints (`/v1/auth/token`, `/v1/sign/key`, `/v1/generate_qr`, `/v1/intra-transaction/status`) are tested against UAT and return 0000 where expected, 3001 on missing records. RS256 signing works.
- We're holding the production cutover until we can validate end-to-end with at least one of: a UAT DK app payment, a real-money cross-bank payment in production, or a confirmed UAT cross-bank payment.

Thanks!

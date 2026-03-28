# Spaceship Registrar Module for WHMCS

A full-featured WHMCS registrar module integrating the [Spaceship.com](https://www.spaceship.com) domain API.

---

## Features

| Feature | Supported |
|---|---|
| Domain Registration | ✅ |
| Domain Transfer (inbound) | ✅ |
| Domain Renewal | ✅ |
| Nameserver Management | ✅ |
| DNS Host Record Management | ✅ |
| WHOIS / Contact Details | ✅ |
| Registrar Lock (Transfer Lock) | ✅ |
| ID / WHOIS Privacy Protection | ✅ |
| EPP / Auth Code Retrieval | ✅ |
| Private Nameserver Registration | ✅ |
| Domain Availability Check | ✅ |
| Premium Domain Support | ✅ |
| TLD & Pricing Sync | ✅ |
| Domain Status Sync (cron) | ✅ |
| Transfer Status Sync (cron) | ✅ |
| Auto-Renew Sync | ✅ (via hook) |
| Client Area Domain Info Widget | ✅ |
| Module Logging | ✅ |

---

## Requirements

- WHMCS 7.6 or later (7.10+ recommended for TLD Pricing Sync)
- PHP 7.1 or later
- PHP cURL extension enabled
- A [Spaceship.com](https://www.spaceship.com) account with API access
- API Key with the following permission scopes:
  - `domains:read`
  - `domains:write`
  - `domains:billing`
  - `domains:transfer`
  - `contacts:read`
  - `contacts:write`
  - `dnsrecords:read`
  - `dnsrecords:write`
  - `asyncoperations:read`

---

## Installation

### 1. Upload Module Files

Copy the module folder to your WHMCS installation:

```
your-whmcs-root/
└── modules/
    └── registrars/
        └── spaceship/
            ├── spaceship.php   ← Main module
            └── hooks.php       ← Auto-renew & privacy hooks
```

### 2. Activate the Module

1. Log in to your **WHMCS Admin Area**
2. Navigate to **Setup → Domain Registrars** (WHMCS 8+: **System Settings → Domain Registrars**)
3. Find **Spaceship** in the list and click **Activate**

### 3. Configure API Credentials

1. Generate your API Key & Secret at:
   **https://www.spaceship.com/application/api-manager/**
2. Ensure the key has all required permission scopes listed above
3. Back in WHMCS, click **Configure** next to Spaceship
4. Enter your **API Key** and **API Secret**
5. Set your preferred **Default Privacy Protection** level
6. Click **Save Changes**

### 4. Import TLDs & Pricing (Optional but Recommended)

1. Navigate to **Utilities → Registrar TLD Sync** (WHMCS 7.10+)
2. Select **Spaceship** from the registrar dropdown
3. Click **Fetch Pricing** — this will pull live register/renew/transfer prices
4. Apply your desired margin and import

### 5. Enable Domain Sync Cron

Ensure the WHMCS cron job is running. Domain expiry and status will sync automatically.

In **Configuration → System Settings → Automation Settings**:
- ✅ **Domain Sync Enabled**
- ✅ **Sync Next Due Date** (recommended)

---

## DNS Management

The module supports full DNS record management from within WHMCS:

**Supported record types:** A, AAAA, CNAME, MX, TXT, SPF, NS, PTR, SRV, ALIAS, CAA, TLSA, SVCB, HTTPS

Clients can manage DNS records from:
**Client Area → My Domains → [Domain] → Manage DNS**

Admins can manage from:
**Admin Area → Domain → [Domain] → DNS Management**

---

## Module Logging

All API requests and responses are logged and viewable in:

**Utilities → Module Log** (filter by `spaceship`)

API Keys and Secrets are automatically redacted from logs.

---

## Troubleshooting

### "Could not create registrant contact"
- Ensure the contact has a valid phone number in `+CC.NNNNNN` format
- Check the Module Log for the exact API error response

### "API Error (HTTP 401)"
- Your API Key or Secret is incorrect
- Regenerate keys at https://www.spaceship.com/application/api-manager/

### "API Error (HTTP 403)"
- Your API Key is missing a required permission scope
- Edit the key and enable all scopes listed in Requirements above

### "Registration failed" / 202 returned
- Spaceship processes registrations asynchronously (202 Accepted)
- The domain will appear as Pending in WHMCS; the Domain Sync cron will update it to Active once the registration completes at Spaceship
- Check the async operation status in **Utilities → Module Log**

### TLD Pricing Sync returns empty results
- Ensure your API key has `domains:read` scope
- Some TLDs may not be supported by Spaceship — they are silently skipped

---

## File Structure

```
modules/registrars/spaceship/
├── spaceship.php    Main registrar module
│                    - getConfigArray()         Module settings
│                    - MetaData()               Module metadata
│                    - RegisterDomain()         New registration
│                    - TransferDomain()         Inbound transfer
│                    - RenewDomain()            Renewal
│                    - GetDomainInformation()   Full domain info (WHMCS 7.6+)
│                    - GetNameservers()         Get nameservers
│                    - SaveNameservers()        Update nameservers
│                    - GetRegistrarLock()       Get transfer lock
│                    - SaveRegistrarLock()      Toggle transfer lock
│                    - GetContactDetails()      WHOIS contacts
│                    - SaveContactDetails()     Update WHOIS
│                    - GetDNS()                 Get DNS records
│                    - SaveDNS()                Save DNS records
│                    - IDProtectToggle()        Privacy on/off
│                    - GetEPPCode()             Auth/EPP code
│                    - RegisterNameserver()     Child NS registration
│                    - ModifyNameserver()       Child NS modification
│                    - DeleteNameserver()       Child NS deletion
│                    - CheckAvailability()      Domain search
│                    - Sync()                   Expiry/status sync
│                    - TransferSync()           Transfer status sync
│                    - GetTldPricing()          TLD & pricing import
│                    - RequestDelete()          Domain deletion stub
│                    - ClientArea()             Client area widget
│
└── hooks.php        WHMCS hooks
                     - AfterRegistrarRegistration  → Auto-renew sync
                     - AfterRegistrarRenewal       → Auto-renew sync
                     - AfterRegistrarTransfer      → Auto-renew sync
                     - DomainIDProtectToggle       → Email protection sync
```

---

## API Reference

- **Spaceship API Docs:** https://docs.spaceship.dev/
- **WHMCS Registrar Module Docs:** https://developers.whmcs.com/domain-registrars/
- **API Manager:** https://www.spaceship.com/application/api-manager/

---

## Version History

| Version | Notes |
|---|---|
| 1.0.0 | Initial release – full registration, DNS, sync, TLD pricing |

---

## License

Released for use with WHMCS. Provided as-is without warranty.

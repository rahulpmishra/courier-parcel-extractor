# HostGator Frontend Deploy

## Frontend source

Canonical frontend source:

```text
D:\KURIERWALA\parcel-extractor-control-desk\frontend
```

## Deployment package

Latest generated package:

```text
D:\KURIERWALA\parcel-extractor-control-desk\hostgator-deploy\hostgator-public_html.zip
```

## Upload flow

1. Open HostGator File Manager
2. Go to your target `public_html`
3. Upload `hostgator-public_html.zip`
4. Extract it in place

## Important permissions

These folders must be writable:

- `public_html/storage`
- `public_html/storage/activity`
- `public_html/storage/daily`
- `public_html/storage/jobs`
- `public_html/storage/pending`
- `public_html/storage/uploads`

Recommended folder permission:

```text
755
```

## Common issue

If activity view is blank on live but works locally, first check:

```text
public_html/storage/activity
```

If that folder is not writable, activity JSON files will not be created.

# Role and permission matrix

| Capability | Guest | Tenant | Owner | Admin |
|---|:---:|:---:|:---:|:---:|
| Browse/search/map/listing reviews | ✓ | ✓ | ✓ | ✓ |
| Favorite, compare, saved searches, recommendations | login prompt | ✓ | — | — |
| Start listing enquiry / tenant review / report listing | login prompt | ✓ | — | — |
| Read/reply own conversation | — | participant | participant | — |
| Profile/password/notifications | — | own | own | own |
| Create/edit/upload/submit/deactivate listing | — | — | owned only | — |
| See owned listing history/reviews | — | — | owned only | — |
| Verify owners / manage user status | — | — | — | ✓ |
| Approve/reject/suspend/restore listing | — | — | — | ✓ |
| Hide/restore reviews / send announcements | — | — | — | ✓ |
| Analytics, AI status and audit log | — | — | — | ✓ |

Every protected action is enforced by Sanctum, active-account middleware, role middleware and controller ownership/participant checks. Admin registration is rejected publicly; an administrator cannot suspend their own active account. UI visibility is convenience, not authorization.

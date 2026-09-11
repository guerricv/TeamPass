<!-- docs/features/folders.md -->

## Overview

Folders are the organisational unit of Teampass. Every item (password, note, file) belongs to exactly one folder. Folder structure is hierarchical — a folder can have any number of sub-folders — and access to items is always controlled at the folder level through roles.

See [Rights](rights.md) for a full explanation of how folder permissions work.

---

## Folder list

The page displays all folders in a tree-shaped table. Each row shows:

- **Folder name** — indented to reflect its depth in the hierarchy
- **Parent path** — breadcrumb showing the full path from the root
- **Password complexity** — the minimum complexity required for items stored in this folder
- **Renewal period** — the number of days after which items are flagged for review (0 = no renewal)
- **Restrictions** — whether items can be created or edited without meeting the complexity requirement

### Filtering the list

Three filters at the top of the table can be combined freely:

| Filter | Effect |
|--------|--------|
| **Depth** | Shows only folders up to the selected hierarchy level (useful on large trees) |
| **Complexity** | Shows only folders with the selected minimum complexity |
| **Search** | Filters folder names in real time |

---

## Creating a folder

Click **New** in the toolbar to open the creation form.

| Field | Required | Description |
|-------|----------|-------------|
| **Title** | Yes | Display name of the folder |
| **Parent folder** | Yes | Where to place this folder in the hierarchy. Select *Root* to create a top-level folder |
| **Password complexity** | Yes | Minimum complexity level required for items stored here. Teampass enforces this on item creation and editing |
| **Renewal delay (days)** | No | Number of days before items in this folder are considered outdated. `0` disables renewal reminders |
| **Icon** | No | FontAwesome class displayed next to the folder name in the tree (e.g. `fas fa-server`) |
| **Icon on selection** | No | Alternative icon shown when the folder is currently selected |
| **Create without complexity** | No | When checked, users can add items without meeting the minimum complexity. Use with care |
| **Edit without complexity** | No | When checked, users can modify existing items without meeting the minimum complexity |

The two **Special** options apply to items in this folder. They do not allow a new
subfolder to have a lower minimum password strength than its parent.

New subfolders inherit the parent's two option values as defaults, including when
created from the Items page, through the API, or during an import. The creation form
prefills these defaults when selecting a parent; either option can be changed before
saving or afterward. Root folders default to both options disabled. This is a one-time
copy: later changes to the parent do not change existing subfolders, and moving an
existing folder preserves its options. This behavior is independent of the
`subfolder_rights_as_parent` setting for role permissions.

> 💡 Icons use the same FontAwesome classes as item icons. See [Items — adding an icon](items.md#adding-icon-to-item-or-folder).

### Password complexity levels

| Level | Label |
|-------|-------|
| 0 | No complexity required |
| 1 | Weak |
| 2 | Medium |
| 3 | Strong |
| 4 | Very strong |

The complexity level set on a folder acts as a **floor**: Teampass will warn or block users attempting to store a password below this level.

---

## Editing a folder

Click any folder row to open the **edit sidebar** on the right. The sidebar contains the same fields as the creation form.

Changes are saved immediately when clicking **Save** in the sidebar.

> 🔔 Changing the parent folder moves the entire subtree. All child folders move with it, and all role-based permissions remain unchanged — they are attached to the folder, not to its position in the tree.

---

## Deleting folders

1. Check the checkbox beside each folder to delete (checking a parent automatically selects its children).
2. Click **Delete** in the toolbar.
3. Confirm by checking the acknowledgement box in the confirmation modal.

> 🔔 Deleting a folder also deletes **all items and sub-folders** it contains. This operation cannot be undone. Items are permanently removed along with their encrypted data and sharekeys.

---

## Folder permissions and roles

Folders themselves do not store who can access them. Access is configured on **Roles** (see [Roles](roles.md)): each role defines a permission type for each folder it covers.

When an administrator assigns a role to a user, that user automatically gains the access defined in the role for every folder the role covers.

### Permission types summary

| Type | Can create | Can edit | Can delete |
|------|:----------:|:--------:|:----------:|
| W — Write | ✅ | ✅ | ✅ |
| ND — No delete | ✅ | ✅ | ❌ |
| NE — No edit | ✅ | ❌ | ✅ |
| NDNE — No edit, no delete | ✅ | ❌ | ❌ |
| R — Read only | ❌ | ❌ | ❌ |

For the full resolution logic when a user has multiple roles, see [Rights](rights.md).

---

## Personal folders

When the personal folder feature is enabled globally (in Settings), each user can have a private folder visible only to them. Its title is set to the user's ID internally and displayed as their login name in the interface.

Personal folders are not subject to role-based access control. No other user (including administrators) can access them through the normal interface.

> 💡 A user's personal folder is created either automatically at account creation (if the option is enabled) or manually by an administrator from the user's form.

---

## Renewal reminders

When a folder has a renewal delay set, Teampass compares the last modification date of each item against that delay. Items not updated within the configured period appear with a visual warning in the items list.

Setting the renewal delay to `0` disables this feature for the folder.

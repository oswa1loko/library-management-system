# LibraryManage API v1 Quickstart

Base URL:

```text
http://localhost/librarymanage/api/v1
```

## 1) Check session user

```bash
curl -X GET "http://localhost/librarymanage/api/v1/auth/me.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID"
```

## 2) Create API token (session required)

```bash
curl -X POST "http://localhost/librarymanage/api/v1/auth/token_create.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID" ^
  -d "label=Postman" ^
  -d "expires_in_days=30" ^
  -d "scopes=read,write"
```

Save the returned `token` value. It is shown once.

Rotate a token:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/auth/token_rotate.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID" ^
  -d "token_id=3"
```

## 3) Use Bearer token on protected POST endpoints

Borrow book:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/borrows/create.php" ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -d "book_id=1" ^
  -d "days=7"
```

Request return:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/borrows/return_request.php" ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -d "borrow_id=10"
```

Submit payment with proof file:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/payments/create.php" ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -F "penalty_id=5" ^
  -F "amount=100.00" ^
  -F "proof=@C:/path/to/proof.jpg"
```

## 4) List and revoke your tokens (session required)

List:

```bash
curl -X GET "http://localhost/librarymanage/api/v1/auth/tokens.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID"
```

Revoke:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/auth/token_revoke.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID" ^
  -d "token_id=3"
```

Revoke all:

```bash
curl -X POST "http://localhost/librarymanage/api/v1/auth/token_revoke_all.php" ^
  -b "PHPSESSID=YOUR_SESSION_ID"
```

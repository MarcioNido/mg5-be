## Money Guru

### Backup and Restore Database

```bash
docker exec mg5-be-mysql-1 /usr/bin/mysqldump -u root --password=Pass123456 moneyguru5 > backup.sql
docker exec -i mg5-be-mysql-1 /usr/bin/mysql -u root --password=Pass123456 moneyguru5 < backup.sql
```

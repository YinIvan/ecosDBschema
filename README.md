# ecosDBschema
将ecos架构中的dbschema更新逻辑扒出来了,以后可以用到其他PHP框架中

用商派ecos框架产品时看见数据库字段是在PHP文件里维护的,修改文件里的数据库字段后 执行cmd update命令,数据库也会跟着变化.
这样项目迁移时不用导出数据库表结构了,也相当于有了一份最新的数据库字典,省去文档维护麻烦


@author chu_sky@163.com 

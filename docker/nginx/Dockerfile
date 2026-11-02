FROM nginx:1.13

ADD nginx.conf /etc/nginx/
ADD website.template /etc/nginx/conf.d/
RUN rm /etc/nginx/conf.d/default.conf

ADD run.sh /
RUN chmod +x /run.sh

CMD ["/run.sh"]

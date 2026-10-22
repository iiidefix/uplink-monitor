# Build this Debian package into dist/.
#   make                     build dist/<package>_<version>_all.deb
#   make VERSION=1.1.1       build another version
#   make clean               remove build output
PACKAGE    := $(shell sed -n 's/^Package: //p' debian/control)
VERSION    ?= 1.1.0
MAINTAINER ?= iiidefix <iiidefix@git.iiidefix.net>
DEB        := dist/$(PACKAGE)_$(VERSION)_all.deb
ROOT       := build/root

.PHONY: all clean
all: $(DEB)

$(DEB): Makefile $(shell find debian files -type f)
	rm -rf build
	mkdir -p $(ROOT)/DEBIAN dist
	cp -a files/. $(ROOT)/
	cp -a debian/. $(ROOT)/DEBIAN/
	find $(ROOT) -type d -exec chmod 0755 {} +
	find $(ROOT) -type f -exec chmod 0644 {} +
	chmod 0755 $(ROOT)/usr/sbin/*
	chmod 0600 $(ROOT)/etc/default/uplink-watchdog
	for s in config preinst postinst prerm postrm; do [ ! -f $(ROOT)/DEBIAN/$$s ] || { chmod 0755 $(ROOT)/DEBIAN/$$s; sh -n $(ROOT)/DEBIAN/$$s; }; done
	for f in $(ROOT)/usr/sbin/*; do bash -n $$f; done
	sed -i -e 's/@VERSION@/$(VERSION)/g' -e 's/@MAINTAINER@/$(MAINTAINER)/' $(ROOT)/DEBIAN/control
	echo "Installed-Size: $$(du -sk --exclude=DEBIAN $(ROOT) | cut -f1)" >> $(ROOT)/DEBIAN/control
	dpkg-deb --root-owner-group -Zxz --build $(ROOT) $@
	rm -rf build

clean:
	rm -rf build dist
